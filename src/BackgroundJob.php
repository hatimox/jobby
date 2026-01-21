<?php

namespace Jobby;

class BackgroundJob
{
    /**
     * @var Helper
     */
    protected Helper $helper;

    /**
     * @var string
     */
    protected string $job;

    /**
     * @var string
     */
    protected string $tmpDir;

    /**
     * @var array
     */
    protected array $config;

    public function __construct(string $job, array $config, ?Helper $helper = null)
    {
        $this->job = $job;
        $this->config = $config + [
            'recipients'     => null,
            'mailer'         => null,
            'maxRuntime'     => null,
            'smtpHost'       => null,
            'smtpPort'       => null,
            'smtpUsername'   => null,
            'smtpPassword'   => null,
            'smtpSender'     => null,
            'smtpSenderName' => null,
            'smtpSecurity'   => null,
            'runAs'          => null,
            'environment'    => null,
            'runOnHost'      => null,
            'output'         => null,
            'output_stdout'  => null,
            'output_stderr'  => null,
            'dateFormat'     => null,
            'enabled'        => null,
            'haltDir'        => null,
            'mattermostUrl'  => null,
            'slackChannel'   => null,
            'slackUrl'       => null,
            'slackSender'    => null,
            'mailSubject'    => null,
        ];

        $this->config['output_stdout'] = $this->config['output_stdout'] === null ? $this->config['output'] : $this->config['output_stdout'];
        $this->config['output_stderr'] = $this->config['output_stderr'] === null ? $this->config['output'] : $this->config['output_stderr'];

        $this->helper = $helper ?: new Helper();

        $this->tmpDir = $this->helper->getTempDir();
    }

    public function run(): void
    {
        $lockFile = $this->getLockFile();

        try {
            $this->checkMaxRuntime($lockFile);
        } catch (Exception $e) {
            $this->log('ERROR: ' . $e->getMessage(), 'stderr');
            $this->notify($e->getMessage());

            return;
        }

        if (!$this->shouldRun()) {
            return;
        }

        $lockAcquired = false;
        try {
            $this->helper->acquireLock($lockFile);
            $lockAcquired = true;

            if (isset($this->config['closure'])) {
                $this->runFunction();
            } else {
                $this->runFile();
            }
        } catch (InfoException $e) {
            $this->log('INFO: ' . $e->getMessage(), 'stderr');
        } catch (Exception $e) {
            $this->log('ERROR: ' . $e->getMessage(), 'stderr');
            $this->notify($e->getMessage());
        }

        if ($lockAcquired) {
            $this->helper->releaseLock($lockFile);

            // remove log file if empty
            $logfile = $this->getLogfile();
            if ($logfile !== false && is_file($logfile) && (filesize($logfile) <= 2 || file_get_contents($logfile) === "[]")) {
                unlink($logfile);
            }
        }
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * @throws Exception
     */
    protected function checkMaxRuntime(string $lockFile): void
    {
        $maxRuntime = $this->config['maxRuntime'];
        if ($maxRuntime === null) {
            return;
        }

        if ($this->helper->getPlatform() === Helper::WINDOWS) {
            throw new Exception('"maxRuntime" is not supported on Windows');
        }

        $runtime = $this->helper->getLockLifetime($lockFile);
        if ($runtime < $maxRuntime) {
            return;
        }

        throw new Exception("MaxRuntime of $maxRuntime secs exceeded! Current runtime: $runtime secs");
    }

    /**
     * @deprecated
     */
    protected function mail(string $message): void
    {
        if (empty($this->config['recipients'])) {
            return;
        }

        $this->helper->sendMail(
            $this->job,
            $this->config,
            $message
        );
    }

    protected function notify(string $message): void
    {
        if (!empty($this->config['recipients'])) {
            $this->helper->sendMail(
                $this->job,
                $this->config,
                $message
            );
        }

        if (!empty($this->config['mattermostUrl'])) {
            $this->helper->sendMattermostAlert(
                $this->job,
                $this->config,
                $message
            );
        }

        if (!empty($this->config['slackChannel']) && !empty($this->config['slackUrl'])) {
            $this->helper->sendSlackAlert(
                $this->job,
                $this->config,
                $message
            );
        }


    }

    protected function getLogfile(string $output = 'stdout'): string|false
    {
        $logfile = $this->config['output_'.$output];
        if ($logfile === null) {
            return false;
        }


        $logs = dirname($logfile);
        if (!file_exists($logs)) {
            mkdir($logs, 0755, true);
        }

        return $logfile;
    }

    protected function getLockFile(): string
    {
        $tmp = $this->tmpDir;
        $job = $this->helper->escape($this->job);

        if (!empty($this->config['environment'])) {
            $env = $this->helper->escape($this->config['environment']);

            return "$tmp/$env-$job.lck";
        } else {
            return "$tmp/$job.lck";
        }
    }

    protected function shouldRun(): bool
    {
        if (!$this->config['enabled']) {
            return false;
        }

        if (($haltDir = $this->config['haltDir']) !== null) {
            if (file_exists($haltDir . DIRECTORY_SEPARATOR . $this->job)) {
                return false;
            }
        }

        $host = $this->helper->getHost();
        if (strcasecmp($this->config['runOnHost'], $host) !== 0) {
            return false;
        }

        return true;
    }

    protected function log(string $message, string $output = 'stdout'): void
    {
        $now = date($this->config['dateFormat'], $_SERVER['REQUEST_TIME']);

        if ($logfile = $this->getLogfile($output)) {
            file_put_contents($logfile, "[$now] [$this->job] $message\n", FILE_APPEND);
        }
    }

    protected function runFunction(): void
    {
        $command = unserialize($this->config['closure']);

        ob_start();
        try {
            $retval = $command();
        } catch (\Throwable $e) {
            if ($logfile = $this->getLogfile('stderr')) {
                file_put_contents($this->getLogfile('stderr'), "Error! " . $e->getMessage() . "\n", FILE_APPEND);
            }
            $retval = $e->getMessage();
        }
        $content = ob_get_contents();
        if ($logfile = $this->getLogfile()) {
            if(strlen($content) > 2){
                file_put_contents($this->getLogfile(), $content, FILE_APPEND);
            }
        }
        ob_end_clean();

        if ($retval !== true) {
            throw new Exception("Closure did not return true! Returned:\n" . print_r($retval, true));
        }
    }

    protected function runFile(): void
    {
        // If job should run as another user, we must be on *nix and
        // must have sudo privileges.
        $isUnix = ($this->helper->getPlatform() === Helper::UNIX);
        $useSudo = '';

        if ($isUnix) {
            $runAs = $this->config['runAs'];
            $isRoot = (posix_getuid() === 0);
            if (!empty($runAs) && $isRoot) {
                $useSudo = "sudo -u $runAs";
            }
        }

        // Start execution. Run in foreground (will block).
        $command = $this->config['command'];
        $stdoutLogfile = $this->getLogfile() ?: $this->helper->getSystemNullDevice();
        $stderrLogfile = $this->getLogfile('stderr') ?: $this->helper->getSystemNullDevice();
        $command = "$useSudo $command 1>> \"$stdoutLogfile\" 2>> \"$stderrLogfile\"";

        if (!$isUnix && $stdoutLogfile === $stderrLogfile) {
            $command = "$useSudo $command >> \"$stdoutLogfile\" 2>&1";
        }

        exec($command, $dummy, $retval);

        if ($retval !== 0) {
            throw new Exception("Job exited with status '$retval'.");
        }
    }
}
