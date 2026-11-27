<?php

declare(strict_types=1);

namespace App\Modules\Restaurants\Services;

use App\Common\Exceptions\ApiException;
use App\Common\Validation\WapiRules;
use App\Modules\Restaurants\Presenters\RestaurantPresenter;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Calcule si un restaurant est ouvert à un instant donné, dans SON fuseau horaire.
 *
 * - Jours : 1 = lundi … 7 = dimanche (ISO-8601, comme wf_restaurant_opening_hours).
 * - Plage traversant minuit (ex. 18:00 → 02:00) : la fin de soirée appartient au jour d'ouverture.
 * - opens_at = closes_at : ouvert 24 h sur 24 ce jour-là.
 * - Jour absent, is_closed = 1 ou horaires vides : fermé.
 */
final class OpeningHoursService
{
    private const TIME_PATTERN = '/\A([01]\d|2[0-3]):[0-5]\d\z/';

    /**
     * @return list<array<string, mixed>>
     */
    public function list(int $restaurantId): array
    {
        $rows = db_connect()->table('wf_restaurant_opening_hours')
            ->where('restaurant_id', $restaurantId)
            ->orderBy('day_of_week', 'ASC')
            ->get()
            ->getResultArray();

        return array_map(RestaurantPresenter::hour(...), $rows);
    }

    /**
     * Remplace le planning hebdomadaire. Chaque jour (1 = lundi … 7 = dimanche) apparaît au plus une fois ;
     * un jour absent est considéré comme fermé.
     *
     * @param mixed $days Liste de { day_of_week, is_closed, opens_at "HH:MM", closes_at "HH:MM" }
     *
     * @return list<array<string, mixed>>
     */
    public function replace(int $restaurantId, mixed $days): array
    {
        if (! is_array($days) || ! array_is_list($days) || count($days) > 7) {
            throw ApiException::validation(['days' => 'Le champ days doit être une liste de 7 jours au maximum.']);
        }

        $errors = [];
        $rows   = [];

        foreach ($days as $index => $day) {
            $prefix = "days.{$index}.";

            if (! is_array($day)) {
                $errors[$prefix . 'day_of_week'] = 'Jour invalide.';

                continue;
            }

            $number = $day['day_of_week'] ?? null;

            if (! is_int($number) && ! (is_string($number) && ctype_digit($number))) {
                $errors[$prefix . 'day_of_week'] = 'Le jour doit être un entier de 1 (lundi) à 7 (dimanche).';

                continue;
            }

            $number = (int) $number;

            if ($number < 1 || $number > 7) {
                $errors[$prefix . 'day_of_week'] = 'Le jour doit être un entier de 1 (lundi) à 7 (dimanche).';

                continue;
            }

            if (isset($rows[$number])) {
                $errors[$prefix . 'day_of_week'] = 'Ce jour est déclaré plusieurs fois.';

                continue;
            }

            $closed = isset($day['is_closed']) && (new WapiRules())->wf_boolean($day['is_closed']) && WapiRules::toBool($day['is_closed']);

            if ($closed) {
                $rows[$number] = ['day_of_week' => $number, 'is_closed' => 1, 'opens_at' => null, 'closes_at' => null];

                continue;
            }

            foreach (['opens_at', 'closes_at'] as $field) {
                if (! is_string($day[$field] ?? null) || preg_match(self::TIME_PATTERN, $day[$field]) !== 1) {
                    $errors[$prefix . $field] = 'Heure attendue au format HH:MM (00:00 à 23:59).';
                }
            }

            if (! isset($errors[$prefix . 'opens_at']) && ! isset($errors[$prefix . 'closes_at'])) {
                $rows[$number] = [
                    'day_of_week' => $number,
                    'is_closed'   => 0,
                    'opens_at'    => $day['opens_at'] . ':00',
                    'closes_at'   => $day['closes_at'] . ':00',
                ];
            }
        }

        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        $db = db_connect();
        $db->transException(true)->transStart();
        $db->table('wf_restaurant_opening_hours')->where('restaurant_id', $restaurantId)->delete();

        if ($rows !== []) {
            ksort($rows);
            $db->table('wf_restaurant_opening_hours')->insertBatch(array_map(
                static fn (array $row): array => $row + ['restaurant_id' => $restaurantId],
                array_values($rows),
            ));
        }

        $db->transComplete();

        return $this->list($restaurantId);
    }

    /**
     * @param list<array{day_of_week: int|string, opens_at: string|null, closes_at: string|null, is_closed: bool|int|string}> $hours
     */
    public function isOpenAt(array $hours, string $timezone, DateTimeImmutable $moment): bool
    {
        try {
            $local = $moment->setTimezone(new DateTimeZone($timezone));
        } catch (Throwable) {
            $local = $moment->setTimezone(new DateTimeZone('Africa/Kinshasa'));
        }

        $byDay = [];

        foreach ($hours as $row) {
            $byDay[(int) $row['day_of_week']] = $row;
        }

        $today     = (int) $local->format('N');
        $yesterday = $today === 1 ? 7 : $today - 1;
        $now       = $local->format('H:i:s');

        // Plage du jour même
        if (($slot = $this->slot($byDay[$today] ?? null)) !== null) {
            [$opens, $closes] = $slot;

            if ($opens === $closes) {
                return true;
            }

            if ($opens < $closes ? ($now >= $opens && $now < $closes) : $now >= $opens) {
                return true;
            }
        }

        // Débordement après minuit de la plage de la veille
        if (($slot = $this->slot($byDay[$yesterday] ?? null)) !== null) {
            [$opens, $closes] = $slot;

            if ($closes < $opens && $now < $closes) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed>|null $row
     *
     * @return array{0: string, 1: string}|null
     */
    private function slot(?array $row): ?array
    {
        if ($row === null || filter_var($row['is_closed'], FILTER_VALIDATE_BOOL) || $row['opens_at'] === null || $row['closes_at'] === null) {
            return null;
        }

        return [substr((string) $row['opens_at'], 0, 8), substr((string) $row['closes_at'], 0, 8)];
    }
}
