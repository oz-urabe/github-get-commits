<?php

namespace OzVision\Command;

use Cache\Adapter\Redis\RedisCachePool;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

class GithubCommitAggregateCommand extends Command
{
    protected function configure()
    {
        date_default_timezone_set('Asia/Tokyo');

        $this
            ->setName('app:github-commit-aggregate')
            ->setDescription('Github のコミット数を集計する');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $redis = new \Redis();
        $redis->connect('127.0.0.1', 6379);

        $configs = Yaml::parseFile(realpath(__DIR__.'/../../config.yml'));

        // Create a PSR6 cache pool

        $client = new \Github\Client();
        $client->addCache(new RedisCachePool($redis));
        $client->authenticate($configs['github_key'], null, \Github\Client::AUTH_HTTP_TOKEN);

        $page = 1;
        $start = new \DateTime(date('Y-m-d 00:00:00', strtotime(sprintf('- %d day', date('N') + 6))));
        $end = new \DateTime(date('Y-m-d 23:59:59', strtotime(sprintf('- %d day', date('N')))));
        $count = [];
        while (true) {
            $repositories = $client->api('organization')->repositories($configs['target_user'], 'all', $page);
            if (!count($repositories)) {
                break;
            }

            foreach ($repositories as $repo) {
                $commits = $client->api('repo')->commits()->all($configs['target_user'], $repo['name'], array(
                    'sha' => 'master',
                    'since' => $start->format('c'),
                    'until' => $end->format('c'),
                ));
                foreach ($commits as $commit) {
                    $author = $commit['author']['login'];
                    $count[$author] = isset($count[$author]) ? $count[$author]+1 : 1;
                }
            }
            $page++;
        }

        var_dump($count);
    }
}
