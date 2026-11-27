<?php

declare(strict_types=1);

namespace Tests\Unit\Restaurants;

use App\Modules\Restaurants\Services\OpeningHoursService;
use CodeIgniter\Test\CIUnitTestCase;
use DateTimeImmutable;
use DateTimeZone;

/**
 * @internal
 */
final class OpeningHoursServiceTest extends CIUnitTestCase
{
    /** 2026-11-23 est un lundi. */
    private function at(string $localTime, string $timezone = 'Africa/Kinshasa'): DateTimeImmutable
    {
        return new DateTimeImmutable($localTime, new DateTimeZone($timezone));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function hours(): array
    {
        return [
            ['day_of_week' => 1, 'opens_at' => '09:00:00', 'closes_at' => '22:00:00', 'is_closed' => 0],
            ['day_of_week' => 5, 'opens_at' => '18:00:00', 'closes_at' => '02:00:00', 'is_closed' => 0],
            ['day_of_week' => 6, 'opens_at' => '00:00:00', 'closes_at' => '00:00:00', 'is_closed' => 0],
            ['day_of_week' => 7, 'opens_at' => null, 'closes_at' => null, 'is_closed' => 1],
        ];
    }

    public function testRegularDay(): void
    {
        $service = new OpeningHoursService();

        $this->assertFalse($service->isOpenAt($this->hours(), 'Africa/Kinshasa', $this->at('2026-11-23 08:59')));
        $this->assertTrue($service->isOpenAt($this->hours(), 'Africa/Kinshasa', $this->at('2026-11-23 09:00')));
        $this->assertTrue($service->isOpenAt($this->hours(), 'Africa/Kinshasa', $this->at('2026-11-23 21:59')));
        $this->assertFalse($service->isOpenAt($this->hours(), 'Africa/Kinshasa', $this->at('2026-11-23 22:00')));
    }

    public function testOvernightSlotSpillsIntoNextDay(): void
    {
        $service = new OpeningHoursService();

        $this->assertTrue($service->isOpenAt($this->hours(), 'Africa/Kinshasa', $this->at('2026-11-27 23:30')), 'vendredi soir');
        $this->assertTrue($service->isOpenAt($this->hours(), 'Africa/Kinshasa', $this->at('2026-11-28 01:30')), 'samedi 1 h 30 (suite du vendredi)');
    }

    public function testAllDayAndClosedDays(): void
    {
        $service = new OpeningHoursService();

        $this->assertTrue($service->isOpenAt($this->hours(), 'Africa/Kinshasa', $this->at('2026-11-28 15:00')), 'samedi 24 h/24');
        $this->assertFalse($service->isOpenAt($this->hours(), 'Africa/Kinshasa', $this->at('2026-11-29 12:00')), 'dimanche fermé');
        $this->assertFalse($service->isOpenAt($this->hours(), 'Africa/Kinshasa', $this->at('2026-11-24 12:00')), 'mardi non renseigné');
        $this->assertFalse($service->isOpenAt([], 'Africa/Kinshasa', $this->at('2026-11-23 12:00')));
    }

    public function testRestaurantTimezoneIsUsed(): void
    {
        $service = new OpeningHoursService();
        // 21 h 30 UTC le lundi = 22 h 30 à Kinshasa (fermé) et 23 h 30 à Lubumbashi (fermé) ;
        // 20 h 30 UTC = 21 h 30 à Kinshasa (ouvert) mais 22 h 30 à Lubumbashi (fermé).
        $moment = $this->at('2026-11-23 20:30', 'UTC');

        $this->assertTrue($service->isOpenAt($this->hours(), 'Africa/Kinshasa', $moment));
        $this->assertFalse($service->isOpenAt($this->hours(), 'Africa/Lubumbashi', $moment));
    }
}
