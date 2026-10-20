<?php
declare(strict_types=1);

/**
 * Run with:
 * ./vendor/bin/phpunit tests/AllocationEngineTest.php
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/services/UafAllocationEngine.php';

final class AllocationEngineTest extends TestCase
{
    private UafAllocationEngine $engine;

    protected function setUp(): void
    {
        $pdo = $this->createMock(PDO::class);
        $this->engine = new UafAllocationEngine($pdo);
    }

    private function callIsEligible(array $student): bool
    {
        $ref = new ReflectionClass($this->engine);
        $m = $ref->getMethod('isEligible');
        $m->setAccessible(true);
        /** @var bool $out */
        $out = $m->invoke($this->engine, $student);
        return $out;
    }

    public function testIsEligibleFalseWhenDuesTrueUafTrue(): void
    {
        $this->assertFalse($this->callIsEligible(['has_unpaid_dues' => 1, 'is_uaf_student' => 1]));
    }

    public function testIsEligibleFalseWhenDuesFalseUafFalse(): void
    {
        $this->assertFalse($this->callIsEligible(['has_unpaid_dues' => 0, 'is_uaf_student' => 0]));
    }

    public function testIsEligibleFalseWhenDuesTrueUafFalse(): void
    {
        $this->assertFalse($this->callIsEligible(['has_unpaid_dues' => 1, 'is_uaf_student' => 0]));
    }

    public function testIsEligibleTrueWhenDuesFalseUafTrue(): void
    {
        $this->assertTrue($this->callIsEligible(['has_unpaid_dues' => 0, 'is_uaf_student' => 1]));
    }

    public function testPhdNoDuesNoViolationsCgpaThreePointFive(): void
    {
        $this->assertSame(535.0, $this->engine->calculatePriorityScore([
            'degree_level' => 'PhD',
            'cgpa' => 3.5,
            'past_violations_count' => 0,
        ]));
    }

    public function testMsNoDuesNoViolationsCgpaThree(): void
    {
        $this->assertSame(330.0, $this->engine->calculatePriorityScore([
            'degree_level' => 'MS/MPhil',
            'cgpa' => 3.0,
            'past_violations_count' => 0,
        ]));
    }

    public function testUgYearThreeNoDuesNoViolationsCgpaThreePointEight(): void
    {
        $this->assertSame(188.0, $this->engine->calculatePriorityScore([
            'degree_level' => 'Undergraduate',
            'year_of_study' => 3,
            'cgpa' => 3.8,
            'past_violations_count' => 0,
        ]));
    }

    public function testUgYearOneTwoViolationsCgpaTwo(): void
    {
        $this->assertSame(0.0, $this->engine->calculatePriorityScore([
            'degree_level' => 'Undergraduate',
            'year_of_study' => 1,
            'cgpa' => 2.0,
            'past_violations_count' => 2,
        ]));
    }

    public function testPhdWithDisabilityCgpaFour(): void
    {
        $this->assertSame(740.0, $this->engine->calculatePriorityScore([
            'degree_level' => 'PhD',
            'cgpa' => 4.0,
            'has_disability' => 1,
            'past_violations_count' => 0,
        ]));
    }

    public function testUgYearTwoInternationalDistanceOneFiftyCgpaThree(): void
    {
        $this->assertSame(330.0, $this->engine->calculatePriorityScore([
            'degree_level' => 'Undergraduate',
            'year_of_study' => 2,
            'is_international' => 1,
            'distance_from_home' => 150,
            'cgpa' => 3.0,
            'past_violations_count' => 0,
        ]));
    }

    public function testScoreFloorNeverBelowNegativeThousand(): void
    {
        $this->assertSame(-1000.0, $this->engine->calculatePriorityScore([
            'degree_level' => 'Undergraduate',
            'year_of_study' => 1,
            'cgpa' => 0.0,
            'past_violations_count' => 10000,
        ]));
    }

    public function testCgpaClampedAtFourWhenInputFive(): void
    {
        $this->assertSame(190.0, $this->engine->calculatePriorityScore([
            'degree_level' => 'Undergraduate',
            'year_of_study' => 3,
            'cgpa' => 5.0,
            'past_violations_count' => 0,
        ]));
    }
}

