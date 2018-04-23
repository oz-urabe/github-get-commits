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

    protected $start;
    protected $end;

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

        $teams = $this->getCommits($repositories);

        $this->notify($teams);
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
        $users = [];

        foreach ($repositories as $repository) {
            $commits = $this->client->api('repo')->commits()->all($this->configs['target_user'], $repository, array(
                'sha' => 'master',
                'since' => $this->start->format('c'),
                'until' => $this->end->format('c'),
            ));
            foreach ($commits as $commit) {
                $author = $commit['author']['login'];
                $users[$author] = isset($users[$author]) ? $users[$author]+1 : 1;
            }
        }

        $teams = [];
        foreach ($this->configs['teams'] as $teamName => $members) {
            $teams[$teamName] = 0;
            foreach ($users as $userName => $commitCount) {
                if (in_array($userName, $members, true)) {
                    $teams[$teamName] += $commitCount;
                    unset($users[$userName]);
                }
            }
        }

        $teams['other'] = 0;
        foreach ($users as $commitCount) {
            $teams['other'] += $commitCount;
        }

        return $teams;
    }

    protected function notify($teams)
    {
        $message = sprintf('@here *%s ~ %s: チームごとのコミット数* のレポート'.PHP_EOL.PHP_EOL, $this->start->format('Y年m月d日'), $this->end->format('Y年m月d日'));
        $configs = $this->configs['slack'];
        $settings = [
            'username' => $configs['username'],
            'channel' => $configs['channel'],
            'link_names' => (bool) $configs['link_names'],
            'mrkdwn_in' => ['pretext', 'text', 'title', 'fields', 'fallback'],
        ];
        $client = new \Maknz\Slack\Client($configs['webhook_url'], $settings);
        $base = '_%s_: `%d commits`'.PHP_EOL;
        foreach ($teams as $team => $commitCount) {
            $message .= sprintf($base, $team, $commitCount);
        }

        $message .= PHP_EOL.'<https://github.com/oz-urabe/github-get-commits|PR歓迎>';

        $client->enableMarkdown()->send($message);
    }

    private function prepare()
    {
        $this->configs = Yaml::parseFile(realpath(__DIR__.'/../../config.yml'));

        $redis = new \Redis();
        $redis->connect('127.0.0.1', 6379);

        $this->client = new \Github\Client();
        $this->client->addCache(new RedisCachePool($redis));
        $this->client->authenticate($this->configs['github_key'], null, \Github\Client::AUTH_HTTP_TOKEN);

        $this->start = new \DateTime(date('Y-m-d 00:00:00', strtotime('- 1 day - 1 month')));
        $this->end = new \DateTime(date('Y-m-d 23:59:59', strtotime('- 1 day')));
    }
}
