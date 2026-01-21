<?php

namespace Jobby\Tests;

use Jobby\Exception;
use Jobby\Helper;
use Jobby\InfoException;
use Jobby\Jobby;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * @coversDefaultClass Jobby\Helper
 */
class HelperTest extends TestCase
{
    /**
     * @var Helper
     */
    private $helper;

    /**
     * @var string
     */
    private $tmpDir;

    /**
     * @var string
     */
    private $lockFile;

    /**
     * @var string
     */
    private $copyOfLockFile;

    /**
     * {@inheritdoc}
     */
    protected function setUp(): void
    {
        $this->helper = new Helper();
        $this->tmpDir = $this->helper->getTempDir();
        $this->lockFile = $this->tmpDir . '/test.lock';
        $this->copyOfLockFile = $this->tmpDir . "/test.lock.copy";
    }

    /**
     * {@inheritdoc}
     */
    protected function tearDown(): void
    {
        unset($_SERVER['APPLICATION_ENV']);
    }

    /**
     * @param string $input
     * @param string $expected
     *
     * @dataProvider dataProviderTestEscape
     */
    public function testEscape($input, $expected): void
    {
        $actual = $this->helper->escape($input);
        $this->assertEquals($expected, $actual);
    }

    /**
     * @return array
     */
    public function dataProviderTestEscape(): array
    {
        return [
            ['lower', 'lower'],
            ['UPPER', 'upper'],
            ['0123456789', '0123456789'],
            ['with    spaces', 'with_spaces'],
            ['invalid!@#$%^&*()chars', 'invalidchars'],
            ['._-', '._-'],
        ];
    }

    /**
     * @covers ::getPlatform
     */
    public function testGetPlatform(): void
    {
        $actual = $this->helper->getPlatform();
        $this->assertContains($actual, [Helper::UNIX, Helper::WINDOWS]);
    }

    /**
     * @covers ::getPlatform
     */
    public function testPlatformConstants(): void
    {
        $this->assertNotEquals(Helper::UNIX, Helper::WINDOWS);
    }

    /**
     * @covers ::acquireLock
     * @covers ::releaseLock
     */
    public function testAquireAndReleaseLock(): void
    {
        $this->helper->acquireLock($this->lockFile);
        $this->helper->releaseLock($this->lockFile);
        $this->helper->acquireLock($this->lockFile);
        $this->helper->releaseLock($this->lockFile);
    }

    /**
     * @covers ::acquireLock
     * @covers ::releaseLock
     */
    public function testLockFileShouldContainCurrentPid(): void
    {
        $this->helper->acquireLock($this->lockFile);

        //on Windows, file locking is mandatory not advisory, so you can't do file_get_contents on a locked file
        //therefore, we need to make a copy of the lock file in order to read its contents
        if ($this->helper->getPlatform() === Helper::WINDOWS) {
            copy($this->lockFile, $this->copyOfLockFile);
            $lockFile = $this->copyOfLockFile;
        } else {
            $lockFile = $this->lockFile;
        }

        $this->assertEquals(getmypid(), file_get_contents($lockFile));

        $this->helper->releaseLock($this->lockFile);
        $this->assertEmpty(file_get_contents($this->lockFile));
    }

    /**
     * @covers ::getLockLifetime
     */
    public function testLockLifetimeShouldBeZeroIfFileDoesNotExists(): void
    {
        if (file_exists($this->lockFile)) {
            unlink($this->lockFile);
        }
        $this->assertFalse(file_exists($this->lockFile));
        $this->assertEquals(0, $this->helper->getLockLifetime($this->lockFile));
    }

    /**
     * @covers ::getLockLifetime
     */
    public function testLockLifetimeShouldBeZeroIfFileIsEmpty(): void
    {
        file_put_contents($this->lockFile, '');
        $this->assertEquals(0, $this->helper->getLockLifetime($this->lockFile));
    }

    /**
     * @covers ::getLockLifetime
     */
    public function testLockLifetimeShouldBeZeroIfItContainsAInvalidPid(): void
    {
        if ($this->helper->getPlatform() === Helper::WINDOWS) {
            $this->markTestSkipped("Test relies on posix_ functions");
        }

        file_put_contents($this->lockFile, 'invalid-pid');
        $this->assertEquals(0, $this->helper->getLockLifetime($this->lockFile));
    }

    /**
     * @covers ::getLockLifetime
     */
    public function testGetLocklifetime(): void
    {
        if ($this->helper->getPlatform() === Helper::WINDOWS) {
            $this->markTestSkipped("Test relies on posix_ functions");
        }

        $this->helper->acquireLock($this->lockFile);

        $this->assertEquals(0, $this->helper->getLockLifetime($this->lockFile));
        sleep(1);
        $this->assertEquals(1, $this->helper->getLockLifetime($this->lockFile));
        sleep(1);
        $this->assertEquals(2, $this->helper->getLockLifetime($this->lockFile));

        $this->helper->releaseLock($this->lockFile);
    }

    /**
     * @covers ::releaseLock
     */
    public function testReleaseNonExistin(): void
    {
        $this->expectException(Exception::class);
        $this->helper->releaseLock($this->lockFile);
    }

    /**
     * @covers ::acquireLock
     */
    public function testExceptionIfAquireFails(): void
    {
        $this->expectException(InfoException::class);

        $fh = fopen($this->lockFile, 'r+');
        $this->assertTrue(is_resource($fh));

        $res = flock($fh, LOCK_EX | LOCK_NB);
        $this->assertTrue($res);

        $this->helper->acquireLock($this->lockFile);
    }

    /**
     * @covers ::acquireLock
     */
    public function testAquireLockShouldFailOnSecondTry(): void
    {
        $this->expectException(Exception::class);
        $this->helper->acquireLock($this->lockFile);
        $this->helper->acquireLock($this->lockFile);
    }

    /**
     * @covers ::getTempDir
     */
    public function testGetTempDir(): void
    {
        $valid = [sys_get_temp_dir(), getcwd()];
        foreach (['TMP', 'TEMP', 'TMPDIR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $valid[] = $_SERVER[$key];
            }
        }

        $actual = $this->helper->getTempDir();
        $this->assertContains($actual, $valid);
    }

    /**
     * @covers ::getApplicationEnv
     */
    public function testGetApplicationEnv(): void
    {
        $_SERVER['APPLICATION_ENV'] = 'foo';

        $actual = $this->helper->getApplicationEnv();
        $this->assertEquals('foo', $actual);
    }

    /**
     * @covers ::getApplicationEnv
     */
    public function testGetApplicationEnvShouldBeNullIfUndefined(): void
    {
        $actual = $this->helper->getApplicationEnv();
        $this->assertNull($actual);
    }

    /**
     * @covers ::getHost
     */
    public function testGetHostname(): void
    {
        $actual = $this->helper->getHost();
        $this->assertContains($actual, [gethostname(), php_uname('n')]);
    }

    /**
     * @covers ::sendMail
     * @covers ::getCurrentMailer
     */
    public function testSendMail(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->once())
            ->method('send')
        ;

        $jobby = new Jobby();
        $config = $jobby->getDefaultConfig();
        $config['output'] = 'output message';
        $config['recipients'] = 'a@a.com,b@b.com';

        $helper = new Helper($mailer);
        $email = $helper->sendMail('job', $config, 'message');

        $host = $helper->getHost();
        $expectedEmail = "jobby@$host";

        $this->assertStringContainsString('job', $email->getSubject());
        $this->assertStringContainsString("[$host]", $email->getSubject());

        $fromAddresses = $email->getFrom();
        $this->assertCount(1, $fromAddresses);
        $this->assertInstanceOf(Address::class, $fromAddresses[0]);
        $this->assertEquals($expectedEmail, $fromAddresses[0]->getAddress());
        $this->assertEquals('jobby', $fromAddresses[0]->getName());

        $this->assertStringContainsString($config['output'], $email->getTextBody());
        $this->assertStringContainsString('message', $email->getTextBody());
    }

    /**
     * @return void
     */
    public function testItReturnsTheCorrectNullSystemDeviceForUnix(): void
    {
        $helper = $this->createPartialMock(Helper::class, ["getPlatform"]);
        $helper->expects($this->once())
            ->method("getPlatform")
            ->willReturn(Helper::UNIX);

        $this->assertEquals("/dev/null", $helper->getSystemNullDevice());
    }

    /**
     * @return void
     */
    public function testItReturnsTheCorrectNullSystemDeviceForWindows(): void
    {
        $helper = $this->createPartialMock(Helper::class, ["getPlatform"]);
        $helper->expects($this->once())
               ->method("getPlatform")
               ->willReturn(Helper::WINDOWS);

        $this->assertEquals("NUL", $helper->getSystemNullDevice());
    }
}
