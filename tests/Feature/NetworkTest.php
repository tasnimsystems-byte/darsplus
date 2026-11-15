<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\App;
use Tests\PedagogyFixture;
use Tests\TestCase;

final class NetworkTest extends TestCase
{
    use PedagogyFixture;

    public function testSupervisorComparesSchoolsWithAggregatesOnly(): void
    {
        $this->buildSchool();
        // Averages: s1 80, s2 60, s4 40 → (80 + 60 + 40) ÷ 3 = 60
        $this->assessment($this->mathA, $this->term1, [1 => 80, 2 => 60]);
        $this->assessment($this->mathB, $this->term1, [4 => 40]);
        $other = $this->createSchool();
        $this->createYear($other, 'Other year');
        App::db()->update('schools', ['name' => 'Second network school'], ['id' => $other]);

        $supervisor = $this->createUser('n.supervisor', [['network_supervisor', null]]);
        $this->loginAs($supervisor, $this->school);
        $page = $this->get('/network');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Second network school', $page->body);
        self::assertMatchesRegularExpression('/>\s*60\s*</', $page->body);
        self::assertStringNotContainsString('Student1', $page->body, 'No names, only aggregates.');
        self::assertStringContainsString('href="/network"', $this->get('/')->body);
        self::assertSame(1, $this->auditCount('view', 'network_overview'));
        // The supervisor still cannot open individual records.
        self::assertSame(403, $this->get('/students')->status);
        self::assertSame(403, $this->get('/students/' . $this->s[1])->status);

        // A school principal (reports.view, but not network-level) and the technical
        // administrator (network-level, but no reports.view) are both refused.
        $this->loginAs($this->principal);
        self::assertSame(403, $this->get('/network')->status);
        self::assertStringNotContainsString('href="/network"', $this->get('/')->body);
        $this->loginAs($this->createUser('n.admin', [['system_admin', null]]), $this->school);
        self::assertSame(403, $this->get('/network')->status);
    }
}
