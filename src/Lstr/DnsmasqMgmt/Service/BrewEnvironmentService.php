<?php

namespace Lstr\DnsmasqMgmt\Service;

use Exception;

use Symfony\Component\Process\Process;

class BrewEnvironmentService implements EnvironmentServiceInterface
{
    private $environment;
    private $resolver_dir;
    private $dnsmasq_config_template;
    private $dnsmasq_config;
    private $dnsmasq_dir;

    private $log_service;

    private $setup_commands;
    private $version_commands;

    public function __construct(array $environment, LogService $log_service)
    {
        $this->environment = $environment;
        $this->resolver_dir = '/etc/resolver';
        $this->dnsmasq_config_template = '/usr/local/opt/dnsmasq/dnsmasq.conf.example';
        $this->dnsmasq_config = '/usr/local/etc/dnsmasq.conf';
        $this->dnsmasq_dir = '/usr/local/etc/dnsmasq.d';

        $this->log_service = $log_service;
    }

    public function setupDnsmasq()
    {
        $all_commands = $this->getSetupCommands();
        $sudo_commands = array_map(
            function ($command) {
                if ($command) {
                    $echo_command = escapeshellarg($command);
                    return "echo 'sudo {$command}'\nsudo {$command}";
                }

                return '';
            },
            $all_commands
        );
        $setup_commands = implode("\n", $sudo_commands);

        $shell = <<<SHELL
set -e
set -u

brew install dnsmasq

{$setup_commands}
SHELL;

        $log_service = $this->log_service;

        $process = new Process($shell);
        $process->setTimeout(60);
        $process->setIdleTimeout(60);
        $process->mustRun(function ($type, $buffer) use ($log_service) {
            if (Process::ERR === $type) {
                $this->log_service->error($buffer);
            } else {
                $this->log_service->info($buffer);
            }
        });

        $dnsmasq_config_contents = null;

        $has_file_contents = file_exists($this->dnsmasq_config)
            && filesize($this->dnsmasq_config) <= 0;
        if (!$has_file_contents) {
            $dnsmasq_config_contents = file_get_contents(
                $this->dnsmasq_config_template
            );
        } else {
            $dnsmasq_config_contents = preg_replace(
                '/\r?\n?#BEGIN-DNSMASQ-MGMT.*#END-DNSMASQ-MGMT\r?\n?/s',
                '',
                file_get_contents($this->dnsmasq_config)
            );
        }

        $dnsmasq_config_contents .= <<<TXT


#BEGIN-DNSMASQ-MGMT
conf-dir={$this->dnsmasq_dir}
#END-DNSMASQ-MGMT

TXT;

        if (!file_put_contents($this->dnsmasq_config, $dnsmasq_config_contents)) {
            throw new Exception("Could not write '{$this->dnsmasq_config}'");
        }
    }

    public function clearDnsCache()
    {
        $all_commands = $this->getClearCacheCommands();
        $sudo_commands = array_map(
            function ($command) {
                if ($command) {
                    $echo_command = escapeshellarg($command);
                    return "echo 'sudo {$command}'\nsudo {$command}";
                }

                return '';
            },
            $all_commands
        );
        $command_string = implode("\n", $sudo_commands);

        $shell = <<<SHELL
set -e
set -u
{$command_string}
SHELL;

        $log_service = $this->log_service;

        $process = new Process($shell);
        $process->setTimeout(60);
        $process->setIdleTimeout(60);
        $process->mustRun(function ($type, $buffer) use ($log_service) {
            if (Process::ERR === $type) {
                $this->log_service->error($buffer);
            } else {
                $this->log_service->info($buffer);
            }
        });

        return;
    }

    public function getSetupCommands()
    {
        if ($this->setup_commands) {
            return $this->setup_commands;
        }

        $user  = posix_getpwuid(posix_geteuid());
        $user_name = $user['name'];

        $this->setup_commands = [
            "mkdir -p {$this->dnsmasq_dir} {$this->resolver_dir}",
            "touch {$this->dnsmasq_config}",
            "chown {$user_name}:admin {$this->dnsmasq_config} "
                . "{$this->dnsmasq_dir} {$this->resolver_dir}",
            "cp /usr/local/opt/dnsmasq/homebrew.mxcl.dnsmasq.plist /Library/LaunchDaemons",
            "launchctl unload /Library/LaunchDaemons/homebrew.mxcl.dnsmasq.plist",
            "launchctl load /Library/LaunchDaemons/homebrew.mxcl.dnsmasq.plist",
        ];

        return $this->setup_commands;
    }

    public function getClearCacheCommands()
    {
        $all_commands   = [];
        $all_commands[] = '/bin/launchctl stop homebrew.mxcl.dnsmasq';
        $all_commands[] = '/bin/launchctl start homebrew.mxcl.dnsmasq';
        $all_commands[] = '';
        $all_commands   = array_merge(
            $all_commands,
            $this->getVersionCommand($this->environment['release'])
        );

        return $all_commands;
    }

    private function getVersionCommand($darwin_version)
    {
        list($major_version) = explode('.', $darwin_version);

        $version_commands = $this->getVersionCommands();

        if (!isset($version_commands[$major_version])) {
            throw new Exception("Unknown Darwin version: {$major_version} ({$darwin_version}).");
        }

        return $version_commands[$major_version];
    }

    private function getVersionCommands()
    {
        if ($this->version_commands) {
            return $this->version_commands;
        }

        // darwin 14 = OS X 10.10
        $this->version_commands['14'] = [
            '/usr/sbin/discoveryutil udnsflushcaches',
        ];

        // darwin 13 = OS X 10.9
        $this->version_commands['13'] = [
            '/usr/bin/dscacheutil -flushcache',
            '/usr/bin/killall -HUP mDNSResponder',
        ];

        // darwin 12 = OS X 10.8
        $this->version_commands['12'] = [
            '/usr/bin/killall -HUP mDNSResponder',
        ];

        // darwin 11 = OS X 10.7
        $this->version_commands['11'] = $this->version_commands['12'];

        // darwin 10 = OS X 10.6
        $this->version_commands['10'] = [
            '/usr/bin/dscacheutil -flushcache',
        ];

        // darwin 9 = OS X 10.5
        $this->version_commands['9'] = $this->version_commands['10'];

        return $this->version_commands;
    }
}
