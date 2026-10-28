<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;

/**
 * Text search that matches how people type, not the exact bytes Accurate
 * stores. Accurate descriptions use "Ø" (diameter, 419 items — not on a
 * keyboard) and sometimes a no-break space (U+00A0, e.g. "x 6mm"): typing
 * "O.T = (35/15)" or "x 6mm" must still find them. Registered as the
 * whereLoose / orWhereLoose query-builder macros (AppServiceProvider).
 */
class LooseSearch
{
    /** character in the data => what it is compared as */
    public const FOLD = ['Ø' => 'O', 'ø' => 'o', '⌀' => 'O', "\u{00A0}" => ' '];

    public static function term(string $term): string
    {
        return strtr(trim($term), self::FOLD);
    }

    public static function register(): void
    {
        $apply = function (Builder $query, string $column, string $term, string $boolean) {
            $sql = $query->getGrammar()->wrap($column);
            $bindings = [];
            foreach (self::FOLD as $from => $to) {
                $sql = "REPLACE({$sql}, ?, ?)";
                $bindings[] = $from;
                $bindings[] = $to;
            }
            $bindings[] = '%'.self::term($term).'%';

            return $query->whereRaw("{$sql} LIKE ?", $bindings, $boolean);
        };

        Builder::macro('whereLoose', function (string $column, string $term) use ($apply) {
            /** @var Builder $this */
            return $apply($this, $column, $term, 'and');
        });
        Builder::macro('orWhereLoose', function (string $column, string $term) use ($apply) {
            /** @var Builder $this */
            return $apply($this, $column, $term, 'or');
        });
    }
}
