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

/**
 * shaper settings (get/set) and actions; kept apart from the service
 * controller so changing the traffic shaper needs its own privilege
 */
class ShaperController extends ApiMutableModelControllerBase
{
    protected static $internalModelClass = '\OPNsense\Speedtest\Speedtest';
    protected static $internalModelName = 'speedtest';

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
