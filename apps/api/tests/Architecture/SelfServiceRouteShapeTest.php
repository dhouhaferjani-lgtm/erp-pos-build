<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use Tests\Architecture\Support\SelfServiceRouteRegistry;
use Tests\TestCase;

/**
 * The structural half of the marker's constraint: a self-service route must not
 * be ABLE to address another principal's row.
 *
 * For every allow-listed route the only permitted URI parameters are the
 * caller's own session or token id. A route parameter naming any other resource
 * ({id} on a record, {userId}, {companyId}) fails EVEN IF it is on the
 * allow-list, unless it carries a named exemption with a stated reason.
 */
final class SelfServiceRouteShapeTest extends TestCase
{
    #[Test]
    public function every_allow_listed_route_only_addresses_the_caller(): void
    {
        foreach (SelfServiceRouteRegistry::ALLOW_LIST as $key) {
            if (array_key_exists($key, SelfServiceRouteRegistry::SHAPE_EXEMPTIONS)) {
                continue;
            }

            $uri = substr($key, (int) strpos($key, ' ') + 1);
            $offending = array_values(array_diff(
                SelfServiceRouteRegistry::parameterNames($uri),
                SelfServiceRouteRegistry::SELF_ADDRESSABLE_PARAMETERS,
            ));

            self::assertSame(
                [],
                $offending,
                $key.' carries URI parameter(s) '.implode(', ', $offending)
                .' that name a resource other than the caller\'s own session or token. A self-service '
                .'route that looks a record up by a path id is not eligible for `authz.self` and must '
                .'carry a policy instead (spec 4.4.1 E-3). If the lookup is genuinely scoped to '
                .'$request->user(), add a named entry to SelfServiceRouteRegistry::SHAPE_EXEMPTIONS '
                .'with the reason.',
            );
        }
    }

    #[Test]
    public function every_shape_exemption_is_on_the_allow_list_and_states_a_reason(): void
    {
        foreach (SelfServiceRouteRegistry::SHAPE_EXEMPTIONS as $key => $reason) {
            self::assertContains(
                $key,
                SelfServiceRouteRegistry::ALLOW_LIST,
                'Shape exemption for a route that is not on the allow-list: '.$key,
            );
            self::assertNotSame('', trim($reason), 'Shape exemption without a stated reason: '.$key);
        }
    }

    #[Test]
    public function a_route_parameter_naming_another_principal_is_rejected(): void
    {
        $offending = array_values(array_diff(
            SelfServiceRouteRegistry::parameterNames('api/v1/users/{userId}/notifications/{id}/read'),
            SelfServiceRouteRegistry::SELF_ADDRESSABLE_PARAMETERS,
        ));

        self::assertSame(['userId', 'id'], $offending);
    }

    #[Test]
    public function a_session_scoped_route_parameter_is_accepted(): void
    {
        $offending = array_values(array_diff(
            SelfServiceRouteRegistry::parameterNames('api/v1/support-access/sessions/{session}/exit'),
            SelfServiceRouteRegistry::SELF_ADDRESSABLE_PARAMETERS,
        ));

        self::assertSame([], $offending);
    }
}
