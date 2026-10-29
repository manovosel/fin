<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';

final class EnvelopeVisibilityTest extends DatabaseTestCase
{
    public function testDailySpendIsAlwaysVisibleAndIncludedByRole(): void
    {
        $id = save_envelope($this->db, [
            'name' => 'Envelope A',
            'account' => 'Test account',
            'role' => 'daily',
            'currency' => 'CURRENCY',
            'visibility' => 'hidden',
        ]);

        $row = $this->envelope($id);
        self::assertSame('daily', $row['role']);
        self::assertSame('normal', $row['visibility']);
        self::assertSame(0, $row['hidden']);
    }

    public function testReservedEnvelopeCanHideItsBalanceOrBeShownOnToday(): void
    {
        $id = save_envelope($this->db, [
            'name' => 'Fun & Travel',
            'account' => 'Test account',
            'role' => 'reserved',
            'currency' => 'CURRENCY',
            'visibility' => 'hidden',
        ]);
        self::assertSame('hidden', $this->envelope($id)['visibility']);
        self::assertSame(1, $this->envelope($id)['hidden']);

        save_envelope($this->db, [
            'id' => $id,
            'name' => 'Fun & Travel',
            'account' => 'Test account',
            'role' => 'reserved',
            'currency' => 'CURRENCY',
            'visibility' => 'today',
        ]);

        $row = $this->envelope($id);
        self::assertSame('reserved', $row['role']);
        self::assertSame('today', $row['visibility']);
        self::assertSame(0, $row['hidden']);
    }

    public function testSavingsGoalProgressVisibilityIsIndependentFromBalanceVisibility(): void
    {
        $id = save_envelope($this->db, [
            'name' => 'Emergency fund',
            'account' => 'Test savings',
            'role' => 'savings',
            'visibility' => 'hidden',
            'savings_goal' => 10000,
            'goal_visibility' => 'today',
        ]);

        $row = $this->envelope($id);
        self::assertSame('hidden', $row['visibility']);
        self::assertSame('today', $row['goal_visibility']);
        self::assertSame(10000.0, $row['savings_goal']);

        save_envelope($this->db, [
            'id' => $id,
            'name' => 'Emergency fund',
            'account' => 'Test savings',
            'role' => 'reserved',
            'visibility' => 'normal',
            'savings_goal' => 10000,
            'goal_visibility' => 'today',
        ]);
        $row = $this->envelope($id);
        self::assertSame('hidden', $row['goal_visibility']);
        self::assertSame(0.0, $row['savings_goal']);
    }

    private function envelope(int $id): array
    {
        foreach (get_envelopes($this->db) as $envelope) {
            if ($envelope['id'] === $id) return $envelope;
        }
        self::fail('Envelope not found');
    }
}
