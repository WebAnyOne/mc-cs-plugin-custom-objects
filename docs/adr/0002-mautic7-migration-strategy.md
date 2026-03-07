# ADR-0002: Mautic 7 Migration Strategy for CustomObjectsBundle

**Date:** 2026-03-07
**Status:** Accepted
**Deciders:** Engineering

---

## Context

CustomObjectsBundle had been made to run on Mautic 5.2 (Symfony 5.4, PHP 8.3) and Mautic 6 (Symfony 6.4, PHP 8.3). The goal was to make it functional on Mautic 7.0 (Symfony 7.4, PHP 8.4) using the same crash-first methodology established in ADR-0001.

Mautic 7 introduced two significant breaking changes relative to Mautic 6:

1. **Symfony 7.4** (jumped from 6.4): stricter interface signatures, removed deprecated BC layers
2. **PHP 8.2 enforcement**: implicit nullable parameters (`string $x = null`) that were deprecation warnings in PHP 8.1 are fatal in PHP 8.2+ strict mode

The primary development environment is a DDEV Docker stack. The plugin's `7.x` branch must not be pushed before a functional browser verification.

---

## Decision 1: Iterative crash-first debugging (same as ADR-0001)

Same rationale as ADR-0001 Decision 1. Each fix is verified by running `php bin/console cache:clear` inside DDEV. The session ends when `cache:clear` exits 0 with no fatal warnings and the browser shows a working Custom Objects page.

**New constraint versus ADR-0001:** During `cache:clear`, Symfony's container warmup phase attempts to instantiate some services to discover console commands. This produces `[WARNING] Some commands could not be registered` messages even after the fix is complete if the *previous* compiled container (being used during the warmup transition) contains an error. The fix is to run `cache:clear` twice and confirm the second run is clean, or check that the newly compiled container directory contains correct generated service files.

---

## Decision 2: Composer path repository for the unpushed `7.x` branch

**Problem:** `composer update` in the cloned Mautic instance requires the plugin's `7.x` branch to be resolvable. The branch only exists locally and must not be pushed during development.

**Chosen approach:** Override the `composer.json` VCS repository entry in the local Mautic clone (`/tmp/webanyone-mautic-7`) with a `"type": "path"` repository pointing to the local plugin directory:

```json
{
  "type": "path",
  "url": "/home/edouard/WS/webanyone/mc-cs-plugin-custom-objects",
  "options": { "symlink": false }
}
```

`"symlink": false` forces a hard copy into `vendor/`, mirroring the production install behaviour and ensuring no symlink-into-Docker issues (see ADR-0001 Decision 3).

**Alternatives considered:**

- **Push to a temporary branch and use VCS repo.**
  *Rejected:* Pollutes the remote with work-in-progress. The user explicitly required no push before verification.

- **Symlink via DDEV additional mounts.**
  *Rejected:* The `composer install` flow for Mautic plugins uses `composer/installers` which places the package under `plugins/CustomObjectsBundle/`. A mount at that path would conflict with the Composer-managed installation. The path repo approach lets Composer handle placement correctly.

- **Keep VCS repo and use `--prefer-source`.**
  *Rejected:* `--prefer-source` still clones from the remote URL; an unpushed local branch is not resolvable.

**Tradeoff:** The path repo entry in `/tmp/webanyone-mautic-7/composer.json` is a local-only override that cannot be committed to the Mautic repo. This is acceptable because the clone is a throwaway development environment.

---

## Decision 3: Explicit nullable type hints over `#[\AllowDynamicProperties]` or suppression

**Problem:** PHP 8.2 deprecated implicit nullables. Multiple plugin methods accepted nullable arguments without the `?` prefix:

```php
// Before (deprecated in PHP 8.1, fatal in PHP 8.2+ strict):
public function buildSaveRoute(int $objectId, int $itemId = null): string
public function import(Import $import, array $rowData, CustomObject $co, ImportLogDTO $importLogDto = null): bool
```

**Chosen approach:** Add `?` prefix to every affected parameter:

```php
public function buildSaveRoute(int $objectId, ?int $itemId = null): string
public function import(Import $import, array $rowData, CustomObject $co, ?ImportLogDTO $importLogDto = null): bool
```

**Alternatives considered:**

- **Use `#[\ReturnTypeWillChange]` or similar attributes to suppress.**
  *Rejected:* Those attributes are for return types, not parameter types. No equivalent suppression attribute exists for nullable parameters.

- **Change default to a non-null sentinel value (e.g. `0` for IDs).**
  *Rejected:* Would require callers to be audited for places that pass `null` explicitly. A breaking change with no safety gain.

- **Leave as warnings and add a `declare(strict_types=1)` exception.**
  *Rejected:* `declare(strict_types=1)` is already in every file in the bundle. PHP 8.2 treats implicit nullables as deprecation warnings by default, but Mautic 7 runs on PHP 8.4 where these are errors.

**Tradeoff:** Pure mechanical fix with no behavioural change. Zero risk.

---

## Decision 4: Add `mixed` type hints to DataTransformer methods

**Problem:** Symfony 7 changed `DataTransformerInterface` to declare:

```php
public function transform(mixed $value): mixed;
public function reverseTransform(mixed $value): mixed;
```

The plugin's implementations used untyped parameters (`$value` with no type), which is no longer compatible with the interface in PHP 8.

**Chosen approach:** Add `mixed $value` parameter type and `mixed` return type to every `transform()` and `reverseTransform()` implementation in the bundle.

**Special case — `OptionsTransformer`:** This class had specific return types (`array` and `ArrayCollection`) that are legal subtypes of `mixed`. The correct fix narrows the return:

```php
// Correct:
public function transform(mixed $value): array
public function reverseTransform(mixed $value): ArrayCollection
```

An automated regex replacement erroneously produced `transform(mixed $value): mixed: array` (double return type). The fix was to revert to the original specific types and add only the parameter annotation.

**Lesson:** When applying bulk regex replacements to typed PHP, test against files that already have return types. A pattern like `function transform($value)` would not match `function transform($value): array`, but a pattern targeting the whole signature can corrupt files with specific return types.

---

## Decision 5: `Constraint::validatedBy()` return type

**Problem:** Symfony 7 requires `validatedBy(): string` and `getTargets(): string|array`. The plugin's `CustomObjectTypeValues` had untyped overrides.

**Chosen approach:** Add the required return types directly:

```php
public function validatedBy(): string { ... }
public function getTargets(): string|array { ... }
```

**Note on `getTargets()`:** Symfony 7's interface declares `string|array`. Earlier Symfony versions declared `mixed`. Using `string|array` is correct for Symfony 7 and would also have been valid on earlier versions if the underlying value was always `string|array`.

---

## Decision 6: Register `mautic.helper.export` as a service alias instead of modifying `Config/config.php`

This was the most architecturally complex decision of the migration.

### Background

`Config/config.php` defines services using Mautic's legacy format, processed by `ServicePass` (a Symfony compiler pass). `Config/services.php` registers the same bundle via Symfony's modern autowiring. Both files exist; they interact in a non-obvious way.

`CustomItemExportSchedulerModel` was defined in `Config/config.php` with 14 explicit arguments, including `'mautic.helper.export'`. That service ID was removed in Mautic 7; `ExportHelper` is now registered solely by its FQCN via autowiring in `CoreBundle/Config/services.php`.

### How `ServicePass` resolves arguments (critical detail)

`ServicePass::processArgument()` classifies each string argument by content:

```php
} elseif (is_bool($argument) || str_contains($argument, '\\')) {
    // Parameter or Class — treated as a LITERAL STRING
    $definitionArguments[] = $argument;
} elseif (str_starts_with($argument, '@')) {
    // Service reference
    $definitionArguments[] = new Reference(substr($argument, 1));
} else {
    // Default: service reference
    $definitionArguments[] = new Reference($argument);
}
```

**Key implication:** Any string containing a backslash (i.e. any FQCN) is passed as a *literal string*, not as a service `Reference`. You cannot reference an autowired FQCN-named service from `Config/config.php` by its class name alone.

### How `ServicePass` interacts with `services.php` (second critical detail)

`ServicePass` processes `Config/config.php` entries in a compiler pass, *after* the bundle extension has loaded `Config/services.php`. For each `config.php` service, `ServicePass` does this at line 103:

```php
if ($name !== $details['class']) {
    $container->setAlias($details['class'], new Alias($name));
}
```

`setAlias()` on a container ID that already has a **definition** (from `services.php`) **replaces that definition with the alias**. The subsequent `hasDefinition($class)` check therefore returns `false`, and `ServicePass` creates a brand-new `Definition` with the explicit arguments from `config.php`. The autowired definition is gone.

**Consequence:** The explicit argument list in `config.php` is always used, regardless of what `services.php` does for the same class. Removing the arguments from `config.php` does not fall back to autowiring — it creates a zero-argument `Definition`, which fails at runtime.

### Options considered

**Option A: Change the argument in `config.php` to the FQCN string.**
```php
Mautic\CoreBundle\Helper\ExportHelper::class, // resolves to 'Mautic\CoreBundle\Helper\ExportHelper'
```
*Rejected:* The FQCN contains backslashes → `ServicePass` treats it as a literal string, not a Reference. The constructor receives the string `'Mautic\CoreBundle\Helper\ExportHelper'` instead of an `ExportHelper` instance.

**Option B: Remove all arguments from the `config.php` entry.**
*Rejected:* Due to the `setAlias()` interaction described above, `ServicePass` creates `new Definition($class, [])` with zero arguments. The service fails with "Too few arguments" at runtime.

**Option C: Rewrite `CustomItemExportSchedulerModel` registration to use only `services.php` and remove the entry from `config.php`.**
*Partially viable* but requires auditing every place that references `mautic.custom.model.export_scheduler` by that ID. The alias mechanism in `ServicePass` (line 129) would preserve the old ID only if the `config.php` entry still exists. Removing it silently drops the alias.

**Option D: Add `mautic.helper.export` as a Symfony alias in `Config/services.php`.** *(Chosen)*
```php
$services->alias('mautic.helper.export', Mautic\CoreBundle\Helper\ExportHelper::class);
```

This is loaded by the bundle extension *before* `ServicePass` runs. By the time `ServicePass` processes `'mautic.helper.export'` as an argument, the alias exists in the container. `ServicePass` falls into the default `else` branch (no backslash, not `%`, not `@`) and wraps it in `new Reference('mautic.helper.export')`, which resolves through the alias to `ExportHelper`.

**Tradeoffs of chosen approach:**

| Pro | Con |
|-----|-----|
| Minimal change — one line in `services.php` | Mixes legacy service ID convention (`mautic.dot.notation`) with modern autowiring infrastructure |
| `Config/config.php` argument list stays intact | The alias is invisible to readers of `config.php` alone |
| `mautic.custom.model.export_scheduler` alias is preserved for callers | Requires understanding both files to reason about the full dependency graph |
| Follows the same pattern used by `LeadBundle` (`mautic.lead.model.export_scheduler` aliases `ContactExportSchedulerModel`) | |

**Long-term recommendation:** Port `CustomItemExportSchedulerModel` and other complex models to a `services.php`-only registration, eliminating the `config.php` entry entirely. This removes the `ServicePass` alias-clobbering interaction and makes dependency resolution fully transparent.

---

## Decision 7: `cache:clear` warnings from the transition container

During `cache:clear`, Symfony compiles a new container and simultaneously uses the *old* container to warm up caches (discover commands, etc.). If the old container had a broken service, warnings appear even after the new container is correct.

**Chosen approach:** Accept these transient warnings. Verify correctness by inspecting the *new* compiled container's generated service file directly:

```bash
ddev exec sh -c "cat /var/www/html/var/cache/prod/<NewHash>/getMautic_Custom_Model_ExportSchedulerService.php"
```

If the new file instantiates the model with all required arguments, the fix is correct regardless of warnings printed during the transition.

---

## Deferred issues (out of scope for this migration)

- **`CustomItemPostSaveSubscriberTest`:** An anonymous class extending `RequestStack` is missing a `getCurrentRequest(): ?Request` return type declaration. This is a test-only issue that does not affect runtime.
- **Full `config.php` → `services.php` migration:** All model services in `config.php` use explicit argument lists with Mautic-style service IDs (e.g. `'mautic.helper.user'`, `'mautic.security'`). Some of these IDs may have been aliased rather than defined in Mautic 7. A future task should audit all argument lists against Mautic 7's actual service registry and migrate to pure autowiring.
- **API Platform compatibility:** `Extension/CustomItemListeningExtension.php` implements `QueryCollectionExtensionInterface` from `api-platform/core ^2.x`. Mautic 7 may ship with API Platform 3.x which changed this interface. Not tested.
