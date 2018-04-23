<?php

namespace OzVision\Command;

use Cache\Adapter\Redis\RedisCachePool;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

class GithubCommitAggregateCommand extends Command
{
    private $configs = [];

    protected $client;

    protected function configure()
    {
        date_default_timezone_set('Asia/Tokyo');

        $this
            ->setName('app:github-commit-aggregate')
            ->setDescription('Github のコミット数を集計する');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->prepare();

        $repositories = $this->getRepositories();

        $count = $this->getCommits($repositories);

        var_dump($count);
    }

    protected function getRepositories()
    {
        $page = 1;
        while (true) {
            $repositories = $this->client
                ->api('organization')
                ->repositories($this->configs['target_user'], 'all', $page);

            if (!count($repositories)) {
                break;
            }

            foreach ($repositories as $repository) {
                yield $repository['name'];
            }
            $page++;
        }
    }

    protected function getCommits($repositories)
    {
        $start = new \DateTime(date('Y-m-d 00:00:00', strtotime('- 1 day - 1 month')));
        $end = new \DateTime(date('Y-m-d 23:59:59', strtotime('- 1 day')));
        $count = [];

        foreach ($repositories as $repository) {
            $commits = $this->client->api('repo')->commits()->all($this->configs['target_user'], $repository, array(
                'sha' => 'master',
                'since' => $start->format('c'),
                'until' => $end->format('c'),
            ));
            foreach ($commits as $commit) {
                $author = $commit['author']['login'];
                $count[$author] = isset($count[$author]) ? $count[$author]+1 : 1;
            }
        }

        return $count;
    }

    private function prepare()
    {
        $this->configs = Yaml::parseFile(realpath(__DIR__.'/../../config.yml'));

        $redis = new \Redis();
        $redis->connect('127.0.0.1', 6379);

        $this->client = new \Github\Client();
        $this->client->addCache(new RedisCachePool($redis));
        $this->client->authenticate($this->configs['github_key'], null, \Github\Client::AUTH_HTTP_TOKEN);
    }
}
