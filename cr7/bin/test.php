<?php
/**
 * Dependency-free test runner.
 *
 *   php bin/test.php [filter]
 *
 * Discovers tests/*Test.php, runs every public test* method of classes extending
 * Tests\TestCase. Each test gets a fresh in-memory SQLite database (full schema),
 * a clean session array, settings cache and configuration snapshot.
 * Optional filter matches "Class::method" substrings. Exit code 1 on failure.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/tests/TestCase.php';

use App\Core\App;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Settings;
use App\Install\Installer;
use Tests\AssertionFailed;
use Tests\TestCase;

$filter = $argv[1] ?? '';

/** "(FooTest.php:42)" — the test-file line where an assertion failed. */
$location = static function (Throwable $e): string {
    foreach ($e->getTrace() as $frame) {
        if (isset($frame['file']) && str_ends_with($frame['file'], 'Test.php')) {
            return ' (' . basename($frame['file']) . ':' . $frame['line'] . ')';
        }
    }
    return '';
};
$schema = Installer::schemaStatements('sqlite');
$configSnapshot = Config::all();

$before = get_declared_classes();
foreach (glob(dirname(__DIR__) . '/tests/*Test.php') ?: [] as $file) {
    require_once $file;
}
$classes = array_filter(
    array_diff(get_declared_classes(), $before),
    static fn (string $c): bool => is_subclass_of($c, TestCase::class) && !(new ReflectionClass($c))->isAbstract()
);
sort($classes);

$passed = 0;
$failures = [];
$assertions = 0;
$started = microtime(true);

foreach ($classes as $class) {
    $reflection = new ReflectionClass($class);
    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if (!str_starts_with($method->getName(), 'test') || $method->getDeclaringClass()->getName() !== $class) {
            continue;
        }
        $name = $reflection->getShortName() . '::' . $method->getName();
        if ($filter !== '' && !str_contains($name, $filter)) {
            continue;
        }

        // Fresh environment per test.
        Config::replace($configSnapshot);
        Config::set('db.driver', 'sqlite');
        $pdo = new PDO('sqlite::memory:');
        Database::setPdo($pdo);
        foreach ($schema as $statement) {
            $pdo->exec($statement);
        }
        Settings::flush();
        Auth::reset();
        App::setRequest(null);
        $_SESSION = [];

        $test = new $class();
        try {
            (new ReflectionMethod($test, 'setUp'))->invoke($test);
            $method->invoke($test);
            (new ReflectionMethod($test, 'tearDown'))->invoke($test);
            $passed++;
            echo '.';
        } catch (AssertionFailed $e) {
            $failures[] = "{$name}\n    " . $e->getMessage() . $location($e);
            echo 'F';
        } catch (Throwable $e) {
            $failures[] = "{$name}\n    " . get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
            echo 'E';
        }
        $assertions += $test->assertions;
    }
}

$total = $passed + count($failures);
printf("\n\n%d test(s), %d assertion(s), %d failure(s) in %.2fs\n", $total, $assertions, count($failures), microtime(true) - $started);
foreach ($failures as $i => $failure) {
    echo "\n" . ($i + 1) . ") {$failure}\n";
}
if ($total === 0) {
    echo "No tests found.\n";
}
echo $failures === [] ? "\nOK\n" : "\nFAILED\n";
exit($failures === [] ? 0 : 1);
