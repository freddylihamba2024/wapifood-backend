<?php

declare(strict_types=1);

namespace App\Common\Support;

use Closure;

/**
 * Génération de slugs lisibles et uniques (« Chez Mama Fifi » → « chez-mama-fifi », puis « chez-mama-fifi-2 »…).
 */
final class Slug
{
    public static function make(string $text, int $maxLength = 180): string
    {
        helper(['text', 'url']);

        $slug = url_title(convert_accented_characters(trim($text)), '-', true);
        $slug = trim(mb_substr($slug, 0, $maxLength), '-');

        return $slug !== '' ? $slug : 'element';
    }

    /**
     * @param Closure(string): bool $exists Indique si le slug est déjà pris (y compris par un élément supprimé)
     */
    public static function unique(string $text, Closure $exists, int $maxLength = 180): string
    {
        $base = self::make($text, $maxLength - 6);
        $slug = $base;

        for ($i = 2; $exists($slug); $i++) {
            $slug = $base . '-' . $i;
        }

        return $slug;
    }
}
