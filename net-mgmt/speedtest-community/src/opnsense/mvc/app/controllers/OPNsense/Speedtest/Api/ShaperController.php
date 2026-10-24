<?php

/*
 * Copyright (C) 2026 Blip9575
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 * INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 * AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 * OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 */

namespace OPNsense\Speedtest\Api;

use OPNsense\Base\ApiMutableModelControllerBase;
use OPNsense\Core\Backend;
use OPNsense\Core\Config;
use OPNsense\Cron\Cron;

/**
 * shaper settings (get/set) and actions; kept apart from the service
 * controller so changing the traffic shaper needs its own privilege
 */
class ShaperController extends ApiMutableModelControllerBase
{
    protected static $internalModelClass = '\OPNsense\Speedtest\Speedtest';
    protected static $internalModelName = 'speedtest';

    /**
     * cron tasks owned by the shaper settings: command => [setting, minutes, hours, description]
     */
    private const CRON_JOBS = [
        'speedtest run' => ['schedule', '23', '1,3,5', 'Run scheduled speedtest'],
        'speedtest setshaper' => ['enabled', '53', '5', 'Set shaper bandwidth from speedtest results'],
    ];

    /**
     * save the settings, then add or (en/dis)able the cron tasks that follow them
     */
    public function setAction()
    {
        $result = parent::setAction();
        if (($result['result'] ?? '') == 'saved') {
            $this->syncCron();
        }
        return $result;
    }

    /**
     * a task is created the first time its setting is enabled; afterwards only its
     * enabled flag follows the setting, so a changed schedule is kept
     */
    private function syncCron()
    {
        $settings = $this->getModel()->shaper;
        $cron = new Cron();
        $changed = false;
        foreach (self::CRON_JOBS as $command => [$setting, $minutes, $hours, $description]) {
            $enabled = (string)$settings->$setting == '1' ? '1' : '0';
            $job = null;
            foreach ($cron->jobs->job->iterateItems() as $item) {
                if ((string)$item->origin == 'Speedtest' && (string)$item->command == $command) {
                    $job = $item;
                    break;
                }
            }
            if ($job === null && $enabled == '1') {
                $job = $cron->getNodeByReference('jobs.job.' . $cron->newDailyJob('Speedtest', $command, $description));
                $job->minutes = $minutes;
                $job->hours = $hours;
                $job->enabled = '1';
                $changed = true;
            } elseif ($job !== null && (string)$job->enabled != $enabled) {
                $job->enabled = $enabled;
                $changed = true;
            }
        }
        if ($changed && $cron->performValidation()->count() == 0) {
            $cron->serializeToConfig();
            Config::getInstance()->save();
            (new Backend())->configdRun('cron restart');
        }
    }

    /**
     * set the shaper pipes from the speedtest average
     */
    public function applyAction()
    {
        if (!$this->request->isPost()) {
            return ['status' => 'error', 'message' => gettext('Use POST')];
        }
        return json_decode((new Backend())->configdRun("speedtest setshaper"), true) ??
            ['status' => 'error', 'message' => gettext('No response from configd')];
    }

    /**
     * create pipes, queues and rules on the interface when none are found
     */
    public function createAction()
    {
        if (!$this->request->isPost()) {
            return ['status' => 'error', 'message' => gettext('Use POST')];
        }
        return json_decode((new Backend())->configdRun("speedtest createshaper"), true) ??
            ['status' => 'error', 'message' => gettext('No response from configd')];
    }
}
