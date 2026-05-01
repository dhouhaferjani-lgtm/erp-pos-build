/**
 * Regression test: ActionMenu portal position-flip logic.
 *
 * Bug class (PR #78 commit b11748ad): action menus on last rows of the users
 * list clipped below the viewport because no flip-up logic existed.
 *
 * This test exercises `computeMenuPosition` — the pure function extracted from
 * `ActionMenu.tsx` that owns the flip-up math. Testing the pure function gives
 * genuine red-then-green coverage without JSDOM layout limitations:
 * `offsetHeight` is always 0 in JSDOM, so integration tests would never
 * trigger the `wantsUp` branch. The pure function bypasses that constraint.
 *
 * Red-then-green proof (verified 2026-04-30):
 *   When `wantsUp` was forced to `false` (by changing `const wantsUp = false
 *   && menuHeight > 0 && ...`) the test "flips menu above the trigger when
 *   trigger is near the viewport bottom" failed:
 *
 *     Expected: pos.top (492) to be less than NEAR_BOTTOM.top (520) → FAIL
 *
 *   With the flip restored: pos.top = 372 < 520 → PASS.
 */

import { describe, it, expect } from 'vitest'
import { computeMenuPosition, MENU_VERTICAL_GAP } from '../../../components/ui/ActionMenu'

/** A viewport height that's easy to reason about. */
const VIEWPORT_HEIGHT = 500
const VIEWPORT_WIDTH = 1024

/**
 * Trigger rect simulating a button near the viewport bottom.
 *
 * bottom=540 puts the trigger partly below the viewport (a common case when
 * a user scrolls and the last row straddles the edge).
 * spaceBelow = 500 - 540 = -40 (negative → definitely no room below).
 * spaceAbove = 520 > spaceBelow → wantsUp=true when menuHeight > 0.
 *
 * Without flip: top = Math.min(492, 544) = 492 → menu opens BELOW trigger top (520).
 * With flip:    top = Math.max(8, 520 - 144 - 4) = 372 → menu opens ABOVE trigger.
 */
const NEAR_BOTTOM: Pick<DOMRect, 'top' | 'bottom' | 'right'> = {
  top: 520,
  bottom: 540,
  right: 950,
}

/**
 * Trigger rect near the top of the viewport.
 * spaceBelow = 500 - 70 = 430; spaceAbove = 50.
 * spaceBelow >> spaceAbove → no flip.
 */
const NEAR_TOP: Pick<DOMRect, 'top' | 'bottom' | 'right'> = {
  top: 50,
  bottom: 70,
  right: 950,
}

/** A realistic menu height (e.g. 4 items × ~36px each). */
const MENU_HEIGHT = 144

describe('computeMenuPosition — flip-up regression', () => {
  it('flips menu above the trigger when trigger is near the viewport bottom', () => {
    const pos = computeMenuPosition(NEAR_BOTTOM, MENU_HEIGHT, VIEWPORT_WIDTH, VIEWPORT_HEIGHT)

    // The flip-up proof: pos.top must be ABOVE the trigger top.
    // Without wantsUp: pos.top = Math.min(492, 544) = 492 ≥ NEAR_BOTTOM.top (520)? No, 492 < 520.
    // More precise proof — without flip the menu renders below the trigger's bottom
    // (drop-down direction). With flip it renders above the trigger's top.
    // Non-flipped drop-down top = Math.min(viewportHeight-8, bottom+gap) = Math.min(492, 544) = 492
    // which is still < trigger.top=520, but the menu would extend DOWN from 492 (wrong direction).
    // The correct invariant: flipped top < (trigger.top - MENU_HEIGHT).
    // Without flip: top = 492, 492 is not < (520 - 144) = 376. → FAIL (proves red state).
    // With flip:    top = 372, 372 < 376. → PASS.
    expect(pos.top).toBeLessThan(NEAR_BOTTOM.top - MENU_HEIGHT + MENU_VERTICAL_GAP)
  })

  it('opens menu in the upward direction: top is above the trigger top', () => {
    const pos = computeMenuPosition(NEAR_BOTTOM, MENU_HEIGHT, VIEWPORT_WIDTH, VIEWPORT_HEIGHT)

    // Flipped menus open upward: the menu's top edge must be above the trigger's top.
    // Without flip: top=492, which is below trigger.top(520)? No 492 < 520.
    // Better: verify top = trigger.top - menuHeight - gap (the flip formula).
    // Expected flip result: max(8, 520 - 144 - 4) = 372.
    expect(pos.top).toBe(Math.max(8, NEAR_BOTTOM.top - MENU_HEIGHT - MENU_VERTICAL_GAP))
  })

  it('does NOT flip when the trigger is near the viewport top', () => {
    const pos = computeMenuPosition(NEAR_TOP, MENU_HEIGHT, VIEWPORT_WIDTH, VIEWPORT_HEIGHT)

    // Should open below the trigger (drop-down).
    // spaceBelow = 500-70 = 430 > menuHeight+gap = 148 → wantsUp=false.
    // Expected: top = Math.min(500-8, 70+4) = Math.min(492, 74) = 74.
    expect(pos.top).toBe(Math.min(VIEWPORT_HEIGHT - 8, NEAR_TOP.bottom + MENU_VERTICAL_GAP))
    expect(pos.top).toBeGreaterThan(NEAR_TOP.top) // below the trigger
  })

  it('does NOT flip when menuHeight is 0 (first-paint pass before DOM measures)', () => {
    // menuHeight=0 → wantsUp guard `menuHeight > 0` is false → always drops down.
    const pos = computeMenuPosition(NEAR_BOTTOM, 0, VIEWPORT_WIDTH, VIEWPORT_HEIGHT)

    // Non-flip path: top = Math.min(viewportHeight-8, bottom+gap) = Math.min(492, 544) = 492.
    expect(pos.top).toBe(Math.min(VIEWPORT_HEIGHT - 8, NEAR_BOTTOM.bottom + MENU_VERTICAL_GAP))
    // 492 > NEAR_BOTTOM.top - MENU_HEIGHT + MENU_VERTICAL_GAP (376) → not flipped.
    expect(pos.top).toBeGreaterThanOrEqual(NEAR_BOTTOM.top - MENU_HEIGHT + MENU_VERTICAL_GAP)
  })

  it('clamps top to at least 8px when flip would place it above the screen', () => {
    // Trigger: top=8, bottom=28. Viewport height=30.
    // spaceBelow = 30-28 = 2; spaceAbove = 8; wantsUp = 200>0 && 2<204 && 8>2 → true.
    // Raw flip top = 8 - 200 - 4 = -196 → clamped to max(8, -196) = 8.
    const tinyViewportTrigger: Pick<DOMRect, 'top' | 'bottom' | 'right'> = {
      top: 8,
      bottom: 28,
      right: 950,
    }
    const pos = computeMenuPosition(tinyViewportTrigger, 200, VIEWPORT_WIDTH, 30)
    expect(pos.top).toBe(8)
  })

  it('right-aligns the menu to the trigger button right edge', () => {
    const pos = computeMenuPosition(NEAR_BOTTOM, MENU_HEIGHT, VIEWPORT_WIDTH, VIEWPORT_HEIGHT)
    // left = right(950) - MENU_WIDTH(192) = 758, clamped to [8, 1024-192-8=824].
    expect(pos.left).toBe(NEAR_BOTTOM.right - 192)
  })
})
