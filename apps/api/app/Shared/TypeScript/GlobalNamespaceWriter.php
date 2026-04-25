<?php

declare(strict_types=1);

namespace App\Shared\TypeScript;

use Spatie\TypeScriptTransformer\Structures\TypesCollection;
use Spatie\TypeScriptTransformer\Writers\TypeDefinitionWriter;

/**
 * Emits the nested `declare namespace App.Modules.{…}` hierarchy produced
 * by TypeDefinitionWriter but wraps the whole file in `declare global { … }`
 * so the namespaces are visible globally from every TypeScript module.
 *
 * A trailing `export {};` turns the file into a module so that
 * `moduleDetection: "force"` in apps/web/tsconfig.json is satisfied
 * without shadowing the global declaration.
 */
final class GlobalNamespaceWriter extends TypeDefinitionWriter
{
    public function format(TypesCollection $collection): string
    {
        $inner = parent::format($collection);

        return "declare global {\n".$inner."\n}\n\nexport {};\n";
    }
}
