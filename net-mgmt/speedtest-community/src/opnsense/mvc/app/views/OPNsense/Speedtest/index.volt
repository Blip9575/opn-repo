{# Copyright 2021 Miha Kralj 
*    Redistribution and use in source and binary forms, with or without
*    modification, are permitted provided that the following conditions are met:
*
*    1. Redistributions of source code must retain the above copyright notice,
*       this list of conditions and the following disclaimer.
*
*    2. Redistributions in binary form must reproduce the above copyright
*       notice, this list of conditions and the following disclaimer in the
*       documentation and/or other materials provided with the distribution.
*
*    THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
*    INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
*    AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
*    AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
*    OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
*    SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
*    INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
*    CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
*    ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
*    POSSIBILITY OF SUCH DAMAGE.
#}
<div class="content-box">
    <table class="table table-condensed">
        <tbody>
            <tr>
                <td style="white-space: nowrap;">
                    <span style="margin-right: 2em;">
                        <a href="#" id="toggle_advanced_page"><i class="fa fa-toggle-off fa-fw text-danger"></i></a>
                        <small>{{ lang._('advanced mode') }}</small> <i class="fa fa-cog fa-sm fa-fw text-warning"></i>
                    </span>
                </td>
                <td style="width: 78%; text-align: right; background-color: inherit; border-top-width: 0px;">
                    <small>help </small>
                    <i class="fa fa-toggle-off text-danger" style="cursor: pointer;" id="toggle_help_page"></i>
                </td>
            </tr>
            <tr>
                <td colspan="2">
                    <h2 style="margin-top:1ex;">Statistics</h2>
                </td>
            </tr>
            <tr>
                <td style="width:30%">Speedtest probes:</td>
                <td>
                    <div id="stat_samples">0</div>
                </td>
            </tr>
            <tr>
                <td>Average Download speed:</td>
                <td>
                    <div id="stat_download">0 Mbps (min: 0 Mbps, max: 0 Mbps)</div>
                </td>
            </tr>
            <tr>
                <td>Average Upload speed:</td>
                <td>
                    <div id="stat_upload">0 Mbps (min: 0 Mbps, max: 0 Mbps)</div>
                </td>
            </tr>
            <tr>
                <td>Average Latency:</td>
                <td>
                    <div id="stat_latency">0.00 ms (min: 0.00 ms, max: 0.00 ms)</div>
                </td>
            </tr>
        </tbody>
    </table>
</div>
<h2>Run speedtest</h2>
{# same markup as the core form of the Traffic shaper (layout_partials/base_form), so both boxes line up #}
<div class="content-box">
    <div id="checkingspeedtest">&nbsp;&nbsp;Locating speedtest module...</div>
    <div id="nospeedtest" style="display:none">&nbsp;&nbsp;No installed speedtest modules found. Install
        <button class="btn btn-xs btn-primary" id="cliAct" type="button"><b>speedtest-cli</b><i id="cliAct_progress"></i></button>
    or
        <button class="btn btn-xs btn-primary" id="binAct" type="button"><b>Ookla</b><i id="binAct_progress"></i></button>
    </div>
    <div class="canruntests" style="display:none">
        <div class="table-responsive form-inline">
        <table class="table table-striped table-condensed" style="table-layout: fixed; width: 100%;">
            <colgroup>
                <col style="width: 25%;" />
                <col style="width: 40%;" />
                <col style="width: 35%;" />
            </colgroup>
            <tbody>
                <tr>
                    <td><div class="control-label"><b>{{ lang._('Server list') }}</b></div></td>
                    <td>
                        <select id="speedlist" class="form-control" style="width: 346px; max-width: 100%;">
                            <option value="">{{ lang._('automatic (nearest server)') }}</option>
                        </select>
                        <div class="speedtest-help hidden">
                            <small>{{ lang._('Populates server list. Defaults to nearest.') }}</small>
                        </div>
                    </td>
                    <td>
                        <button class="btn btn-default" id="serverlistAct" type="button">
                            <b>{{ lang._('Get server list') }}</b> <i id="serverlistAct_progress"></i></button>
                    </td>
                </tr>
                <tr>
                    <td><div class="control-label"><b>{{ lang._('Server id') }}</b></div></td>
                    <td>
                        <input type="text" id="serverid" name="serverid" class="form-control" size="50"
                               inputmode="numeric" placeholder="{{ lang._('optional') }}">
                        <div class="speedtest-help hidden">
                            <small>{{ lang._('Leave empty to let speedtest pick the nearest server, or enter the id of a specific server.') }}</small>
                        </div>
                        <small id="serverid_error" class="text-danger" style="display:none"></small>
                    </td>
                    <td>
                        <button class="btn btn-primary" id="reportAct" type="button">
                            <b>{{ lang._('run speedtest') }}</b> <i id="reportAct_progress"></i></button>
                    </td>
                </tr>
            </tbody>
        </table>
        </div>
        {{ partial("layout_partials/base_form",['fields':scheduleForm,'id':'frm_schedule'])}}
        <div class="col-md-12">
            <hr/>
            <button class="btn btn-primary" id="scheduleSaveAct" type="button">
                <b>{{ lang._('Save') }}</b> <i id="scheduleSaveAct_progress"></i></button>
            <button class="btn btn-default" id="scheduleApplyAct" type="button">
                <b>{{ lang._('Apply now') }}</b> <i id="scheduleApplyAct_progress"></i></button>
            <br/><br/>
            <div id="schedule_result"></div>
            <br/>
        </div>
        <div class="clearfix"></div>
    </div>
    <div class="table-responsive">
    <table class="table table-striped table-condensed" style="table-layout: fixed; width: 100%;">
            <colgroup>
                <col style="width: 25%;" />
                <col style="width: 40%;" />
                <col style="width: 35%;" />
            </colgroup>
        <tbody id="test_results" style="display:none">
                <tr>
                    <td><div class="control-label"><b>{{ lang._('Download speed') }}</b></div></td>
                    <td><span id="dlspeed">0 Mbps</span></td>
                    <td></td>
                </tr>
                <tr>
                    <td><div class="control-label"><b>{{ lang._('Upload speed') }}</b></div></td>
                    <td><span id="ulspeed">0 Mbps</span></td>
                    <td></td>
                </tr>
                <tr>
                    <td><div class="control-label"><b>{{ lang._('Latency (ping)') }}</b></div></td>
                    <td><span id="latency">0 ms</span></td>
                    <td></td>
                </tr>
                <tr>
                    <td><div class="control-label"><b>{{ lang._('Speedtest server') }}</b></div></td>
                    <td><div id="ISP1"></div><div id="ISP2"></div><div id="ISP3"></div></td>
                    <td></td>
                </tr>
                <tr>
                    <td><div class="control-label"><b>{{ lang._('Client IP') }}</b></div></td>
                    <td><div id="client"></div></td>
                    <td></td>
                </tr>
                <tr>
                    <td><div class="control-label"><b>{{ lang._('Result id') }}</b></div></td>
                    <td><div id="result"></div></td>
                    <td></td>
                </tr>
        </tbody>
    </table>
    </div>
</div>
<small><div id="version_error" class="text-danger"></div></small>
<div class="hide" id="speedtest_advanced">
    <small><div id="version"></div></small>

    <a href="/ui/cron"><button class="btn btn-xs btn-primary" id="cronAct" type="button">
            <b> schedule in cron </b>
    </button></a>
    <button id="cli1Act" class="btn btn-xs" type="button">
        <b>switch to http speedtest</b>
    </button>
    <button id="bin1Act" class="btn btn-xs" type="button">
        <b>switch to socket speedtest</b>
    </button><br>

</div>

<h2>{{ lang._('Traffic shaper') }}</h2>
<div class="content-box" id="shaper">
    {{ partial("layout_partials/base_form",['fields':shaperForm,'id':'frm_shaper'])}}
    <div class="col-md-12">
        <hr/>
        <button class="btn btn-primary" id="shaperSaveAct" type="button">
            <b>{{ lang._('Save') }}</b> <i id="shaperSaveAct_progress"></i></button>
        <button class="btn btn-default" id="shaperApplyAct" type="button">
            <b>{{ lang._('Apply now') }}</b> <i id="shaperApplyAct_progress"></i></button>
        <button class="btn btn-default" id="shaperCreateAct" type="button">
            <b>{{ lang._('Create shaper') }}</b> <i id="shaperCreateAct_progress"></i></button>
        <br/><br/>
        <div id="shaper_result"></div>
        <br/>
    </div>
</div>

<div class="content-box" id="logs">
    <table id="grid-log" class="table table-condensed">
        <thead>
            <tr>
                <th style="text-align:left">
                    <a href="#" id="toggle_log"><i class="fa fa-toggle-off text-danger"></i></a>
                    <small id="togglelog">show log</small>
                </th>
            </tr>
            <tr><th>
                <div id="exportlog_button" class="speedtest-log" style="display: none;">
                    <a href="/api/speedtest/download/csv">
                        <button class="btn btn-sm btn-primary" id="downloadAct" type="button">
                            <b>Export log</b>
                        </button>
                    </a>
                </div>
            </th>
            <th>
                <div id="clearlog_button" class="speedtest-log" style="display: none;">
                    <button class="btn  btn-sm btn-primary" id="deletelogAct" type="button">
                        <b>{{ lang._('Clear log') }}</b> <i id="deletelogAct_progress"></i></button>
                </div>
            </th></tr>
            <tr id="log_head" class="speedtest-log" style="display: none;">
                <th data-column-id="Timestamp" class="text-left" style="width:7em;">Timestamp (GMT)</th>
                <th data-column-id="ServerId" class="text-left" style="width:3em;">Server id</th>
                <th data-column-id="ServerName" class="text-left" style="width:12em;">Server name</th>
                <th data-column-id="Latency" class="text-left" style="width:2em;">Download</th>
                <th data-column-id="DlSpeed" class="text-left" style="width:3em;">Upload</th>
                <th data-column-id="UlSpeed" class="text-left" style="width:3em;">Latency</th>
            </tr>
        </thead>
        <tbody id="log_block" class="speedtest-log" style="display: none;">
        </tbody>
    </table>
</div>

<script>
    function stat_reload() {
        ajaxCall(url = "/api/speedtest/service/showstat", sendData = {}, callback = function(l, status) {
            $('#stat_samples').html("<b>" + l.samples + "<\/b>")
            $('#stat_latency').html("<b>" + l.latency.avg + " ms<\/b> (min: " + l.latency.min + " ms, max: " + l.latency.max + " ms)")
            $('#stat_download').html("<b>" + l.download.avg + " Mbps<\/b> (min: " + l.download.min + " Mbps, max: " + l.download.max + " Mbps)")
            $('#stat_upload').html("<b>" + l.upload.avg + " Mbps<\/b> (min: " + l.upload.min + " Mbps, max: " + l.upload.max + " Mbps)")
        });
    };
    function log_reload() {
        ajaxCall(url = "/api/speedtest/service/showlog", sendData = {}, callback = function(l, status) {
            var obj = '';
            for (var i = 0; i < l.length; i++) {
                obj +=
                    "<tr><td class=\"text-left\" style=\"\">" + l[i][0] + "</td>" +
                    "<td class=\"text-left\" style=\"\">" + l[i][2] + "</td>" +
                    "<td class=\"text-left\" style=\"\">" + l[i][3] + "</td>" +
                    "<td class=\"text-left\" style=\"\">" + parseFloat(l[i][5]).toFixed(2) + "</td>" +
                    "<td class=\"text-left\" style=\"\">" + parseFloat(l[i][6]).toFixed(2) + "</td>" +
                    "<td class=\"text-left\" style=\"\">" + parseFloat(l[i][7]).toFixed(2) + "</td></tr>"
            }
            $('#log_block').html(obj);
        });
    };
    // the server id field holds "id" or "id (server name)"; returns the id, '' when empty, null when invalid
    function serverid_value() {
        var m = $('#serverid').val().trim().match(/^(\d*)\s*(\(.*\))?$/);
        return m && (m[1] !== '' || m[2] === undefined) ? m[1] : null;
    };
    // pick a server of the list and show it in the server id field as "id (server name)"
    function serverid_select(id) {
        var option = $('#speedlist option').filter(function() { return $(this).val() === id; });
        $('#speedlist').val(id);
        $('#serverid').val(id === '' ? '' : id + " (" + option.data('name') + ")");
        $('#serverid_error').hide();
    };
    function serverlist_reload() {
        $("#serverlistAct_progress").addClass("fa fa-spinner fa-pulse");
        ajaxCall(url = "/api/speedtest/service/serverlist", sendData = {}, callback = function(l, status) {
            $("#serverlistAct_progress").removeClass("fa fa-spinner fa-pulse");
            var selected = serverid_value();
            $('#speedlist').empty().append($('<option/>').val("").text("{{ lang._('automatic (nearest server)') }}"));
            if (Array.isArray(l) && l.length > 0) {
                for (var i = 0; i < l.length; i++) {
                    $('#speedlist').append($('<option/>').val(l[i].id).data('name', l[i].name)
                        .text("(" + l[i].id + ") " + l[i].name + ", " + l[i].location));
                }
                // keep an entered id when it is part of the fetched list, else take the nearest server (listed first)
                var found = selected && $('#speedlist option').filter(function() { return $(this).val() === selected; }).length > 0;
                serverid_select(found ? selected : String(l[0].id));
            } else {
                $('#speedlist').append($('<option/>').val("").prop("disabled", true)
                    .text(l && l.error ? l.error : "{{ lang._('no servers returned') }}"));
            }
        });
    };
    function version_reload() {
        ajaxCall(url = "/api/speedtest/service/version", sendData = {}, callback = function(l, status) {
            $('#checkingspeedtest').hide();
            if (l.version=='none') {
                $('#nospeedtest').show("div");
            } else if (l.error) {
                $('#version_error').text(l.error);
            } else {
                $('.canruntests').show();
                $('#version').text(l.message);
                if (l.version=='binary') {
                    $('#bin1Act').addClass('btn-success disabled')
                    $('#bin1Act').removeClass('btn-primary')
                    $('#cli1Act').removeClass('btn-success disabled')
                    $('#cli1Act').addClass('btn-primary')
                } else {
                    $('#cli1Act').addClass('btn-success disabled')
                    $('#cli1Act').removeClass('btn-primary')
                    $('#bin1Act').removeClass('btn-success disabled')
                    $('#bin1Act').addClass('btn-primary')
                }
            }
            });
    };

    // run a speedtest (serverid empty: nearest server) and show its result
    function run_speedtest(serverid, btn) {
        $("#" + btn + "_progress").addClass("fa fa-spinner fa-pulse");
        ajaxCall(url = "/api/speedtest/service/run/" + (serverid === '' ? '0' : serverid), sendData = {}, callback = function(r, status) {
            $("#" + btn + "_progress").removeClass("fa fa-spinner fa-pulse");
            $("#test_results").attr("style", "display:content");
            if (r.error) {
                $("#dlspeed").text(r.error);
                $("#ulspeed").text("");
                $("#latency").text("");
                $("#ISP1").text("");
                $("#ISP2").text("");
                $("#ISP3").text("");
                $("#client").text("");
                $("#result").text("");
            } else {
                $("#dlspeed").text(r.download + " Mbps");
                $("#ulspeed").text(r.upload + " Mbps");
                $("#latency").text(r.latency + " ms");
                $("#ISP1").text("id: " + r.serverid);
                $("#ISP2").text(r.servername);
                $("#ISP3").text(r.country);
                $("#client").text(r.clientip);
                $("#result").html("<a href=\"" + r.link + "\"  target=\"_blank\">" + r.link + "</a>");
            }
            stat_reload();
            log_reload();
        });
    };
    function shaper_result(r) {
        var text = (r && r.message) ? r.message : "{{ lang._('No response') }}";
        var cls = (r && (r.status == 'error' || r.status == 'disabled')) ? 'text-danger' : 'text-success';
        $('#shaper_result').empty().append($('<span/>').addClass(cls).text(text));
    };
    // save the shaper settings first, then run the given action
    // "Enable traffic shaper" as last saved; null until the settings are loaded
    var shaper_saved_enabled = null;
    function shaper_enabled() {
        return $('[id="speedtest.shaper.enabled"]').is(':checked');
    };
    // Create shaper and Apply now need the shaper enabled; Save stays usable until the
    // unticked state is saved, otherwise the shaper could not be switched off
    function shaper_buttons() {
        var on = shaper_enabled();
        $('#shaperCreateAct, #shaperApplyAct').prop('disabled', !on);
        $('#shaperSaveAct').prop('disabled', !on && shaper_saved_enabled === false);
    };
    function shaper_action(btn, url) {
        $("#" + btn + "_progress").addClass("fa fa-spinner fa-pulse");
        saveFormToEndpoint("/api/speedtest/shaper/set", 'frm_shaper', function() {
            shaper_saved_enabled = shaper_enabled();
            shaper_buttons();
            ajaxCall(url, {}, function(r, status) {
                $("#" + btn + "_progress").removeClass("fa fa-spinner fa-pulse");
                shaper_result(r);
            });
        }, true, function() {
            $("#" + btn + "_progress").removeClass("fa fa-spinner fa-pulse");
        });
    };

    $(document).ready(function() {
        // run on doc load
        version_reload();
        stat_reload();
        log_reload();
        // the shaper form has no info icons or "full help" switch, its help follows the page's help slider
        $('#frm_shaper .control-label a.showhelp, #frm_shaper .control-label i.fa-info-circle').remove();
        $('#frm_schedule .control-label a.showhelp, #frm_schedule .control-label i.fa-info-circle').remove();
        $('#show_all_help_frm_shaper, #show_all_help_frm_schedule').closest('tr').remove();
        // "Create shaper" next to the interface it creates the shaper on, in the column of "run speedtest"
        $('[id="row_speedtest.shaper.interface"] > td:last').prepend($('#shaperCreateAct'));
        mapDataToFormUI({'frm_shaper': "/api/speedtest/shaper/get", 'frm_schedule': "/api/speedtest/shaper/get"}).done(function() {
            $('.selectpicker').selectpicker('refresh');
            shaper_saved_enabled = shaper_enabled();
            shaper_buttons();
        });
        $('[id="speedtest.shaper.enabled"]').change(shaper_buttons);

    });
    $(function() {
        // advanced mode shows/hides the extra buttons (cron, http/socket speedtest)
        $("#toggle_advanced_page").click(function(e) {
            e.preventDefault();
            var icon = $(this).find('i');
            icon.toggleClass("fa-toggle-on fa-toggle-off").toggleClass("text-success text-danger");
            $('#speedtest_advanced').toggleClass('hide', !icon.hasClass("fa-toggle-on"));
        });
        // the help slider shows/hides the help on the page (server fields, shaper form); core's help toggle only covers its own form
        $("#toggle_help_page").click(function() {
            var on = !$(this).hasClass("fa-toggle-on");
            $(this).toggleClass("fa-toggle-on fa-toggle-off").toggleClass("text-success text-danger");
            $('#frm_shaper [data-for^="help_for"], #frm_schedule [data-for^="help_for"], .speedtest-help').toggleClass('hidden', !on);
        });
        // show/hide the log; core's advanced mode toggle only handles elements inside a form
        $("#toggle_log").click(function(e) {
            e.preventDefault();
            var icon = $(this).find('i');
            icon.toggleClass("fa-toggle-on fa-toggle-off").toggleClass("text-success text-danger");
            $('.speedtest-log').toggle(icon.hasClass("fa-toggle-on"));
        });
        // pressing button
        $("#cliAct").click(function() {
            ajaxCall(url = "/api/speedtest/service/installhttp/", sendData = {}, callback = function(r, status) {
                version_reload();
                $('#nospeedtest').hide();
                $('.canruntests').show();
            });
        });
        $("#binAct").click(function() {
            ajaxCall(url = "/api/speedtest/service/installsocket/", sendData = {}, callback = function(r, status) {
                version_reload();
                $('#nospeedtest').hide();
                $('.canruntests').show();
            });
        });
        $("#cli1Act").click(function() {
            ajaxCall(url = "/api/speedtest/service/installhttp/", sendData = {}, callback = function(r, status) {
                version_reload();
                $('#nospeedtest').hide();
                $('.canruntests').show();
            });
        });
        $("#bin1Act").click(function() {
            ajaxCall(url = "/api/speedtest/service/installsocket/", sendData = {}, callback = function(r, status) {
                version_reload();
                $('#nospeedtest').hide();
                $('.canruntests').show();
            });
        });
        // fetch the list of nearest servers on demand
        $("#serverlistAct").click(function() {
            serverlist_reload();
        });
        // picking a server from the list fills the (optional) server id field
        $("#speedlist").change(function() {
            serverid_select($(this).val());
        });
        $("#reportAct").click(function() {
            var serverid = serverid_value();
            if (serverid === null) {
                $('#serverid_error').text("{{ lang._('Please enter a numeric server id or leave the field empty.') }}").show();
                return;
            }
            $('#serverid_error').hide();
            run_speedtest(serverid, 'reportAct');
        });
        $("#shaperSaveAct").click(function() {
            $("#shaperSaveAct_progress").addClass("fa fa-spinner fa-pulse");
            saveFormToEndpoint("/api/speedtest/shaper/set", 'frm_shaper', function() {
                $("#shaperSaveAct_progress").removeClass("fa fa-spinner fa-pulse");
                shaper_saved_enabled = shaper_enabled();
                shaper_buttons();
            }, false, function() {
                $("#shaperSaveAct_progress").removeClass("fa fa-spinner fa-pulse");
            });
        });
        // Save only stores the schedule, Apply now also puts it in cron (it never runs a speedtest)
        $("#scheduleSaveAct").click(function() {
            $("#scheduleSaveAct_progress").addClass("fa fa-spinner fa-pulse");
            saveFormToEndpoint("/api/speedtest/shaper/set", 'frm_schedule', function() {
                $("#scheduleSaveAct_progress").removeClass("fa fa-spinner fa-pulse");
            }, false, function() {
                $("#scheduleSaveAct_progress").removeClass("fa fa-spinner fa-pulse");
            });
        });
        $("#scheduleApplyAct").click(function() {
            $("#scheduleApplyAct_progress").addClass("fa fa-spinner fa-pulse");
            saveFormToEndpoint("/api/speedtest/shaper/set", 'frm_schedule', function() {
                ajaxCall("/api/speedtest/shaper/schedule", {}, function(r, status) {
                    $("#scheduleApplyAct_progress").removeClass("fa fa-spinner fa-pulse");
                    var ok = r && r.status == 'ok';
                    $('#schedule_result').empty().append($('<span/>').addClass(ok ? 'text-success' : 'text-danger')
                        .text(r && r.message ? r.message : "{{ lang._('No response') }}"));
                });
            }, true, function() {
                $("#scheduleApplyAct_progress").removeClass("fa fa-spinner fa-pulse");
            });
        });
        $("#shaperApplyAct").click(function() {
            shaper_action('shaperApplyAct', "/api/speedtest/shaper/apply");
        });
        $("#shaperCreateAct").click(function() {
            stdDialogConfirm(
                "{{ lang._('Create shaper') }}",
                "{{ lang._('Create the WAN_download_p and WAN_upload_p pipes, their queues and rules on the selected interface for each direction that has none? The rules exclude the interface address, so traffic of the firewall itself (and the speedtest) is not shaped.') }}",
                "{{ lang._('Yes') }}", "{{ lang._('Cancel') }}", function() {
                    shaper_action('shaperCreateAct', "/api/speedtest/shaper/create");
                });
        });
        // Delete Log
        $("#deletelogAct").click(function() {
            $("#deletelogAct_progress").addClass("fa fa-spinner fa-pulse");
            ajaxCall(url = "/api/speedtest/service/deletelog/", sendData = {}, callback = function(data, status) {
                stat_reload();
                log_reload();
                $("#deletelogAct_progress").removeClass("fa fa-spinner fa-pulse");
            });
            $('#log_block').html("");
            log_reload();
        });
    });

</script>

