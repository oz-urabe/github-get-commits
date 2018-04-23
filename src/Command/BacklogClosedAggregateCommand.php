<?php

namespace OzVision\Command;

use Cache\Adapter\Redis\RedisCachePool;
use Itigoppo\BacklogApi\Backlog\Backlog;
use Itigoppo\BacklogApi\Connector\ApiKeyConnector;
use OzVision\Util\BetweenDateTime;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

class BacklogClosedAggregateCommand extends Command
{
    private $configs = [];

    protected $backlog;

    protected $betweenDateTime;

    protected function configure()
    {
        date_default_timezone_set('Asia/Tokyo');

        $this
            ->setName('app:backlog-closed-aggregate')
            ->setDescription('Backlog のクローズ数を集計する');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->prepare();

        $teams = $this->groupingByTeams($this->getClosedIssueCounts());

        $this->notify($teams);
    }

    protected function getClosedIssueCounts()
    {
        $allIssues = [];
        $offset = 0;
        while (true) {
            $issues = $this->backlog->issues->load([
                'resolutionId' => [0, 1],
                'statusId' => [4],
                'updatedSince' => $this->betweenDateTime->getStart()->format('Y-m-d'),
                'updatedUntil' => $this->betweenDateTime->getEnd()->format('Y-m-d'),
                'count' => 100,
                'offset' => $offset,
            ]);
            foreach ($issues as $issue) {
                $id = $issue->assignee->id;
                $allIssues[$id] = isset($allIssues[$id]) ? $allIssues[$id] + 1 : 1;
            }

            if (count($issues < 100)) {
                break;
            }
            $offset = $offset + 100;
        }

        return $allIssues;
    }

    protected function groupingByTeams($issues)
    {
        $teams = [];
        foreach ($issues as $memberId => $closedCount) {
            foreach ($this->configs['backlog']['teams'] as $teamName => $memberIds) {
                if (!isset($teams[$teamName])) {
                    $teams[$teamName] = 0;
                }
                if (in_array($memberId, $memberIds)) {
                    $teams[$teamName] += $closedCount;
                    unset($issues[$memberId]);
                }
            }
        }

        $teams['other'] = 0;
        foreach ($issues as $closedCount) {
            $teams['other'] += $closedCount;
        }

        return $teams;
    }

    protected function notify($teams)
    {
        $message = sprintf(
            '@here *%s ~ %s: チームごとのバックログクローズ数* のレポート'.PHP_EOL.PHP_EOL,
            $this->betweenDateTime->getStart()->format('Y年m月d日'),
            $this->betweenDateTime->getEnd()->format('Y年m月d日')
        );
        $configs = $this->configs['slack'];
        $settings = [
            'username' => $configs['username'],
            'channel' => $configs['channel'],
            'link_names' => (bool) $configs['link_names'],
            'mrkdwn_in' => ['pretext', 'text', 'title', 'fields', 'fallback'],
        ];
        $client = new \Maknz\Slack\Client($configs['webhook_url'], $settings);
        $base = '_%s_: `%d closed`'.PHP_EOL;
        foreach ($teams as $team => $commitCount) {
            $message .= sprintf($base, $team, $commitCount);
        }

        $message .= PHP_EOL.'<https://github.com/oz-urabe/github-get-commits|PR歓迎>';

        $client->enableMarkdown()->send($message);
    }

    private function prepare()
    {
        $this->configs = Yaml::parseFile(realpath(__DIR__.'/../../config.yml'));

        $this->backlog = new Backlog(new ApiKeyConnector(
            $this->configs['backlog']['space_id'],
            $this->configs['backlog']['api_key']
        ));

        $this->betweenDateTime = new BetweenDateTime();
    }
}
