<?php

namespace App\Jobs;

use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

class BlockIp extends Job implements ShouldQueue
{
    /**
     * @var string
     */
    private $ip;

    /**
     * @param string $ip
     */
    public function __construct($ip)
    {
        $this->ip = $ip;
    }

    /**
     * @param LoggerInterface $log
     */
    public function handle(LoggerInterface $log)
    {
        // Symfony Process requires an argument array (no shell is involved).
        if (filter_var($this->ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $command = ['sudo', 'ufw', 'insert', '1', 'proto', 'ipv6', 'deny', 'from', $this->ip];
        } elseif (filter_var($this->ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $command = ['sudo', 'ufw', 'insert', '1', 'deny', 'from', $this->ip];
        } else {
            $log->warning('BlockIp: Invalid IP address', ['ip' => $this->ip]);
            return;
        }

        $process = new Process($command);
        $process->run();

        $log->info('Blocking ip', [
            'ip' => $this->ip,
            'command' => $process->getCommandLine(),
            'output' => $process->getOutput(),
            'error_output' => $process->getErrorOutput(),
        ]);

        $job = (new UnblockIp($this->ip))
            ->delay(Carbon::now()->addMinutes(10));

        dispatch($job);

        $log->info('Dispatched UnblockIp');
    }

}
