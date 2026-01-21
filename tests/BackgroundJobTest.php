<?php

namespace Jobby\Tests;

use Jobby\BackgroundJob;
use Jobby\Helper;
use Opis\Closure\SerializableClosure;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * @coversDefaultClass Jobby\BackgroundJob
 */
class BackgroundJobTest extends TestCase
{
    const JOB_NAME = 'name';

    /**
     * @var string
     */
    private $logFile;

    /**
     * @var Helper
     */
    private $helper;

    /**
     * {@inheritdoc}
     */
    protected function setUp(): void
    {
        $this->logFile = __DIR__ . '/_files/BackgroundJobTest.log';
        if (file_exists($this->logFile)) {
            unlink($this->logFile);
        }

        $this->helper = new Helper();
    }

    /**
     * {@inheritdoc}
     */
    protected function tearDown(): void
    {
        if (file_exists($this->logFile)) {
            unlink($this->logFile);
        }
    }

    public function runProvider(): array
    {
        $echo = function () {
            echo 'test';

            return true;
        };
        $uid = function () {
            echo getmyuid();

            return true;
        };
        $job = ['closure' => $echo];

        return [
            'diabled, not run'       => [$job + ['enabled' => false], ''],
            'normal job, run'         => [$job, 'test'],
            'wrong host, not run'    => [$job + ['runOnHost' => 'something that does not match'], ''],
            'current user, run,'     => [['closure' => $uid], getmyuid()],
        ];
    }

    /**
     * @covers ::getConfig
     */
    public function testGetConfig(): void
    {
        $job = new BackgroundJob('test job',[]);
        $this->assertIsArray($job->getConfig());
    }

    /**
     * @dataProvider runProvider
     *
     * @covers ::run
     *
     * @param array  $config
     * @param string $expectedOutput
     */
    public function testRun($config, $expectedOutput): void
    {
        $this->runJob($config);

        $this->assertEquals($expectedOutput, $this->getLogContent());
    }

    /**
     * @covers ::runFile
     */
    public function testInvalidCommand(): void
    {
        $this->runJob(['command' => 'invalid-command']);

        $this->assertStringContainsString('invalid-command', $this->getLogContent());

        if ($this->helper->getPlatform() === Helper::UNIX) {
            $this->assertStringContainsString('not found', $this->getLogContent());
            $this->assertStringContainsString(
                "ERROR: Job exited with status '127'",
                $this->getLogContent()
            );
        } else {
            $this->assertStringContainsString(
                'not recognized as an internal or external command',
                $this->getLogContent()
            );
        }
    }

    /**
     * @covers ::runFunction
     */
    public function testClosureNotReturnTrue(): void
    {
        $this->runJob(
            [
                'closure' => function () {
                    return false;
                },
            ]
        );

        $this->assertStringContainsString(
            'ERROR: Closure did not return true! Returned:',
            $this->getLogContent()
        );
    }

    /**
     * @covers ::getLogFile
     */
    public function testHideStdOutByDefault(): void
    {
        ob_start();
        $this->runJob(
            [
                'closure' => function () {
                    echo 'foo bar';
                },
                'output'  => null,
            ]
        );
        $content = ob_get_contents();
        ob_end_clean();

        $this->assertEmpty($content);
    }

    /**
     * @covers ::getLogFile
     */
    public function testShouldCreateLogFolder(): void
    {
        $logfile = dirname($this->logFile) . '/foo/bar.log';
        $this->runJob(
            [
                'closure' => function () {
                    echo 'foo bar';
                },
                'output'  => $logfile,
            ]
        );

        $dirExists = file_exists(dirname($logfile));
        $isDir = is_dir(dirname($logfile));

        unlink($logfile);
        rmdir(dirname($logfile));

        $this->assertTrue($dirExists);
        $this->assertTrue($isDir);
    }

    /**
     * @covers ::getLogFile
     */
    public function testShouldSplitStderrAndStdout(): void
    {
        $dirname = dirname($this->logFile);
        $stdout = $dirname . '/stdout.log';
        $stderr = $dirname . '/stderr.log';
        $this->runJob(
            [
                'command' => "(echo \"stdout output\" && (>&2 echo \"stderr output\"))",
                'output_stdout' => $stdout,
                'output_stderr' => $stderr,
            ]
        );

        $stdoutContent = file_exists($stdout) ? file_get_contents($stdout) : '';
        $stderrContent = file_exists($stderr) ? file_get_contents($stderr) : '';

        $this->assertStringContainsString('stdout output', $stdoutContent);
        $this->assertStringContainsString('stderr output', $stderrContent);

        if (file_exists($stderr)) {
            unlink($stderr);
        }
        if (file_exists($stdout)) {
            unlink($stdout);
        }
    }

    /**
     * @covers ::mail
     */
    public function testNotSendMailOnMissingRecipients(): void
    {
        $helper = $this->createMock(Helper::class);
        $helper->expects($this->never())
            ->method('sendMail')
        ;
        $helper->method('getHost')->willReturn('localhost');
        $helper->method('getTempDir')->willReturn(sys_get_temp_dir());
        $helper->method('getPlatform')->willReturn(Helper::UNIX);
        $helper->method('escape')->willReturnCallback(function ($input) {
            return preg_replace('/[^a-z0-9_. -]+/', '', strtolower($input));
        });

        $this->runJob(
            [
                'closure'    => function () {
                    return false;
                },
                'recipients' => '',
            ],
            $helper
        );
    }

    /**
     * @covers ::mail
     */
    public function testMailShouldTriggerHelper(): void
    {
        $helper = $this->createMock(Helper::class);
        $helper->expects($this->once())
            ->method('sendMail')
        ;
        $helper->method('getHost')->willReturn('localhost');
        $helper->method('getTempDir')->willReturn(sys_get_temp_dir());
        $helper->method('getPlatform')->willReturn(Helper::UNIX);
        $helper->method('escape')->willReturnCallback(function ($input) {
            return preg_replace('/[^a-z0-9_. -]+/', '', strtolower($input));
        });

        $this->runJob(
            [
                'closure'    => function () {
                    return false;
                },
                'recipients' => 'test@example.com',
            ],
            $helper
        );
    }

    /**
     * @covers ::checkMaxRuntime
     */
    public function testCheckMaxRuntime(): void
    {
        if ($this->helper->getPlatform() !== Helper::UNIX) {
            $this->markTestSkipped("'maxRuntime' is not supported on Windows");
        }

        $helper = $this->createMock(Helper::class);
        $helper->expects($this->once())
            ->method('getLockLifetime')
            ->willReturn(0)
        ;
        $helper->method('getHost')->willReturn('localhost');
        $helper->method('getTempDir')->willReturn(sys_get_temp_dir());
        $helper->method('getPlatform')->willReturn(Helper::UNIX);
        $helper->method('escape')->willReturnCallback(function ($input) {
            return preg_replace('/[^a-z0-9_. -]+/', '', strtolower($input));
        });
        $helper->method('acquireLock')->willReturn(null);
        $helper->method('releaseLock')->willReturn(null);
        $helper->method('getSystemNullDevice')->willReturn('/dev/null');

        $this->runJob(
            [
                'command'    => 'true',
                'maxRuntime' => 1,
            ],
            $helper
        );

        $this->assertEmpty($this->getLogContent());
    }

    /**
     * @covers ::checkMaxRuntime
     */
    public function testCheckMaxRuntimeShouldFailIsExceeded(): void
    {
        if ($this->helper->getPlatform() !== Helper::UNIX) {
            $this->markTestSkipped("'maxRuntime' is not supported on Windows");
        }

        $helper = $this->createMock(Helper::class);
        $helper->expects($this->once())
            ->method('getLockLifetime')
            ->willReturn(2)
        ;
        $helper->method('getHost')->willReturn('localhost');
        $helper->method('getTempDir')->willReturn(sys_get_temp_dir());
        $helper->method('getPlatform')->willReturn(Helper::UNIX);
        $helper->method('escape')->willReturnCallback(function ($input) {
            return preg_replace('/[^a-z0-9_. -]+/', '', strtolower($input));
        });

        $this->runJob(
            [
                'command'    => 'true',
                'maxRuntime' => 1,
            ],
            $helper
        );

        $this->assertStringContainsString(
            'MaxRuntime of 1 secs exceeded! Current runtime: 2 secs',
            $this->getLogContent()
        );
    }

    /**
     * @dataProvider haltDirProvider
     * @covers       ::shouldRun
     *
     * @param bool $createFile
     * @param bool $jobRuns
     */
    public function testHaltDir($createFile, $jobRuns): void
    {
        $dir = __DIR__ . '/_files';
        $file = $dir . '/' . static::JOB_NAME;

        $fs = new Filesystem();

        if ($createFile) {
            $fs->touch($file);
        }

        $this->runJob(
            [
                'haltDir' => $dir,
                'closure' => function () {
                    echo 'test';

                    return true;
                },
            ]
        );

        if ($createFile) {
            $fs->remove($file);
        }

        $content = $this->getLogContent();
        $this->assertEquals($jobRuns, is_string($content) && !empty($content));
    }

    public function haltDirProvider(): array
    {
        return [
            [true, false],
            [false, true],
        ];
    }

    /**
     * @param array  $config
     * @param Helper|null $helper
     */
    private function runJob(array $config, ?Helper $helper = null): void
    {
        $config = $this->getJobConfig($config);

        $job = new BackgroundJob(self::JOB_NAME, $config, $helper);
        $job->run();
    }

    /**
     * @param array $config
     *
     * @return array
     */
    private function getJobConfig(array $config): array
    {
        $helper = new Helper();

        if (isset($config['closure'])) {
            $wrapper = new SerializableClosure($config['closure']);
            $config['closure'] = serialize($wrapper);
        }

        return array_merge(
            [
                'enabled'    => 1,
                'haltDir'    => null,
                'runOnHost'  => $helper->getHost(),
                'dateFormat' => 'Y-m-d H:i:s',
                'schedule'   => '* * * * *',
                'output'     => $this->logFile,
                'maxRuntime' => null,
                'runAs'      => null,
            ],
            $config
        );
    }

    /**
     * @return string
     */
    private function getLogContent(): string
    {
        return file_exists($this->logFile) ? file_get_contents($this->logFile) : '';
    }
}
