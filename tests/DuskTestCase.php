<?php

namespace Tests;

use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Illuminate\Support\Collection;
use Laravel\Dusk\Browser;
use Laravel\Dusk\TestCase as BaseTestCase;
use PHPUnit\Framework\Attributes\BeforeClass;

abstract class DuskTestCase extends BaseTestCase
{
    /**
     * Directory where US-002 browser-test artifacts (screenshots and console
     * logs) are stored, so they are easy to review alongside the spec.
     */
    protected const ARTIFACT_DIR = __DIR__.'/../docs/test-results/US-002';

    /**
     * Prepare for Dusk test execution.
     */
    #[BeforeClass]
    public static function prepare(): void
    {
        if (static::runningInSail()) {
            return;
        }

        // Reuse an already-running ChromeDriver on 9515 (e.g. started manually
        // in a sandboxed CI/host environment) instead of failing to spawn a
        // second one.
        $existing = @fsockopen('127.0.0.1', 9515, $errno, $errstr, 0.5);

        if ($existing !== false) {
            fclose($existing);

            return;
        }

        static::startChromeDriver(['--port=9515']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! is_dir(static::ARTIFACT_DIR)) {
            mkdir(static::ARTIFACT_DIR, 0755, true);
        }

        Browser::$storeScreenshotsAt = static::ARTIFACT_DIR;
        Browser::$storeConsoleLogAt = static::ARTIFACT_DIR;
    }

    /**
     * Create the RemoteWebDriver instance.
     */
    protected function driver(): RemoteWebDriver
    {
        $options = (new ChromeOptions)->addArguments(collect([
            $this->shouldStartMaximized() ? '--start-maximized' : '--window-size=1280,720',
            '--disable-search-engine-choice-screen',
            '--disable-smooth-scrolling',
            // Chrome refuses to start its sandbox when the launching process
            // is root (the default user in the app container), which is a
            // standard, harmless requirement for running Chrome in Docker.
            '--no-sandbox',
            '--disable-dev-shm-usage',
        ])->unless($this->hasHeadlessDisabled(), function (Collection $items) {
            return $items->merge([
                '--disable-gpu',
                '--headless=new',
            ]);
        })->all());

        return RemoteWebDriver::create(
            $_ENV['DUSK_DRIVER_URL'] ?? env('DUSK_DRIVER_URL') ?? 'http://localhost:9515',
            DesiredCapabilities::chrome()->setCapability(
                ChromeOptions::CAPABILITY, $options
            )
        );
    }
}
