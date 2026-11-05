<?php

namespace RRZE\MultisiteManager\Tests\Unit;

use RRZE\MultisiteManager\Config;
use RRZE\MultisiteManager\Support\AtomicLock;
use RRZE\MultisiteManager\Support\WorkerBudget;

/** Requires the disposable WordPress MySQL/MariaDB test database. */
class WorkerCoordinationTest extends \WP_UnitTestCase {
    private function lockName(string $key): string {
        $method = new \ReflectionMethod(AtomicLock::class, 'name');
        return substr($method->invoke(null, $key), 0, 64);
    }

    public function testAnotherDatabaseConnectionCannotAcquireOrReleaseOwnedLocks(): void {
        $other = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $key = 'integration-' . wp_generate_uuid4();
        $name = $this->lockName($key);
        $token = AtomicLock::acquire($key);
        try {
            self::assertNotSame('', $token);
            self::assertSame(0, (int)$other->get_var($other->prepare('SELECT GET_LOCK(%s, 0)', $name)));
            self::assertSame(0, (int)$other->get_var($other->prepare('SELECT RELEASE_LOCK(%s)', $name)));
            self::assertTrue(AtomicLock::owns($key, $token));
            self::assertFalse(AtomicLock::release($key, 'foreign-token'));
            self::assertTrue(AtomicLock::release($key, $token));
            self::assertSame(1, (int)$other->get_var($other->prepare('SELECT GET_LOCK(%s, 0)', $name)));
            self::assertSame('', AtomicLock::acquire($key));
        } finally {
            AtomicLock::release($key, $token);
            $other->get_var($other->prepare('SELECT RELEASE_LOCK(%s)', $name));
            $other->close();
        }
    }

    public function testOtherConnectionOccupiesSharedCapacityUntilItDisconnects(): void {
        $other = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $option = (new Config())->getOptionName();
        $saved = get_site_option($option, []);
        update_site_option($option, array_merge((array)$saved, ['monitoring_max_concurrent_workers' => 2]));
        $budget = new WorkerBudget();
        try {
            foreach ([0, 1] as $slot) {
                self::assertSame(1, (int)$other->get_var($other->prepare('SELECT GET_LOCK(%s, 0)', $this->lockName('background_worker_' . $slot))));
            }
            // The first lock must survive acquiring the second lock.
            self::assertTrue(AtomicLock::isLocked('background_worker_0'));
            self::assertFalse($budget->acquire());
            $other->close();
            self::assertTrue($budget->acquire());
            $nested = new WorkerBudget();
            self::assertTrue($nested->acquire());
            $nested->release();
            self::assertTrue(AtomicLock::isLocked('background_worker_0'));
        } finally {
            $budget->release();
            $other->close();
            update_site_option($option, $saved);
        }
    }
}
