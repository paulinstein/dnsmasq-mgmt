<?php

namespace Lstr\DnsmasqMgmt\Command;

use Exception;

use Lstr\Silex\App\AppAwareInterface;
use Lstr\Silex\App\AppAwareTrait;
use Silex\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AddressRemoveCommand extends Command implements AppAwareInterface
{
    use AppAwareTrait;

    protected function configure()
    {
        $this
            ->setName('address:remove')
            ->setAliases(['remove-address'])
            ->setDescription('Remove an address')
            ->addArgument(
                'hostname',
                InputArgument::REQUIRED,
                'What is the hostname you want to remove?'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $app     = $this->getSilexApplication();
        $service = $app['lstr.dnsmasq'];

        $service->setLoggerIsVerbose(
            OutputInterface::VERBOSITY_VERBOSE <= $output->getVerbosity()
        );

        return $service->removeAddress(
            $input->getArgument('hostname')
        );
    }
}
