<?php

namespace OzVision\Util;

class BetweenDateTime
{
    private $start;

    private $end;

    public function __construct(\DateTime $start = null, \DateTime $end = null)
    {
        $this->start = $start ? clone $start : new \DateTime(date('Y-m-d 00:00:00', strtotime('- 1 day - 1 month')));
        $this->end = $end ? clone $end : new \DateTime(date('Y-m-d 23:59:59', strtotime('- 1 day')));

        // force YYYY-MM-DD 00:00:00 ~ YYYY-MM-DD 23:59:59
        $this->start->setTime(0, 0, 0);
        $this->end->setTime(23, 59, 59);

        $this->validate();
    }

    public function getStart()
    {
        return $this->start;
    }

    public function getEnd()
    {
        return $this->end;
    }

    protected function validate()
    {
        if ($this->start > $this->end) {
            throw new BetweenDateTimeException('Please make $end it from $start in the future');
        }
    }
}

class BetweenDateTimeException extends \InvalidArgumentException
{
}
