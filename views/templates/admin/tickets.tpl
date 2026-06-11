{*
 * Prioritized tickets view. EVERY dynamic value is escaped on output
 * (docs/06 §2). Customer/AI text is rendered as escaped plain text — never |raw,
 * never nofilter — so a <script> in an email subject cannot run in the agent's
 * browser.
 *}
<div class="panel">
    <div class="panel-heading">
        <i class="icon-list"></i> {l s='Prioritized Daktela tickets' d='Modules.Daktela.Admin'}
        <span class="badge">{$score_mode|escape:'html':'UTF-8'}</span>
        <a class="btn btn-primary btn-xs pull-right" href="{$run_sync_url|escape:'html':'UTF-8'}">
            <i class="icon-refresh"></i> {l s='Run sync now' d='Modules.Daktela.Admin'}
        </a>
    </div>

    {if !$daktela_rows}
        <div class="alert alert-info">
            {l s='No tickets yet. Click "Run sync now" or run' d='Modules.Daktela.Admin'} <code>php cli/sync.php</code>.
        </div>
    {else}
    <table class="table">
        <thead>
            <tr>
                <th title="{$tip.priority|escape:'html':'UTF-8'}">{l s='Priority' d='Modules.Daktela.Admin'}</th>
                <th>{l s='Ticket' d='Modules.Daktela.Admin'}</th>
                <th title="{$tip.category|escape:'html':'UTF-8'}">{l s='Category' d='Modules.Daktela.Admin'}</th>
                <th title="{$tip.sentiment|escape:'html':'UTF-8'}">{l s='Sentiment' d='Modules.Daktela.Admin'}</th>
                <th>{l s='Urgency' d='Modules.Daktela.Admin'}</th>
                <th title="{$tip.value|escape:'html':'UTF-8'}">{l s='Value' d='Modules.Daktela.Admin'}</th>
                <th title="{$tip.waiting|escape:'html':'UTF-8'}">{l s='Waiting' d='Modules.Daktela.Admin'}</th>
                <th title="{$tip.manual|escape:'html':'UTF-8'}">{l s='Manual' d='Modules.Daktela.Admin'}</th>
            </tr>
        </thead>
        <tbody>
        {foreach from=$daktela_rows item=row}
            <tr{if $row.flagged} class="warning"{/if}>
                <td>
                    <strong>{$row.effective_score|intval}</strong>
                    {if $row.manual_score !== null}
                        <span class="label label-info" title="{$row.manual_score_by|escape:'html':'UTF-8'}">{l s='manual' d='Modules.Daktela.Admin'}</span>
                    {else}
                        <span class="text-muted">({l s='ai' d='Modules.Daktela.Admin'} {$row.ai_score|intval})</span>
                    {/if}
                    {if $row.flagged}<span class="label label-warning">{l s='review' d='Modules.Daktela.Admin'}</span>{/if}
                </td>
                <td>
                    <strong>{$row.title|escape:'html':'UTF-8'}</strong><br>
                    <span class="text-muted">{$row.summary|escape:'html':'UTF-8'}</span><br>
                    <small>
                        {$row.contact_name|escape:'html':'UTF-8'}
                        &lt;{$row.contact_email|escape:'html':'UTF-8'}&gt;
                        {if $row.order_count > 0}· {$row.order_count|intval} {l s='orders' d='Modules.Daktela.Admin'}{/if}
                        {if $row.has_open_order}· <span class="label label-default">{l s='open order' d='Modules.Daktela.Admin'}</span>{/if}
                    </small>
                    {if $row.suggested_draft && $row.answer_grounded && !$row.needs_human}
                        <div class="alert alert-success" style="margin-top:6px" title="{$tip.draft|escape:'html':'UTF-8'}">
                            <strong>{l s='Suggested draft' d='Modules.Daktela.Admin'}:</strong>
                            {$row.suggested_draft|escape:'html':'UTF-8'}
                        </div>
                    {elseif $row.suggested_draft}
                        <div class="text-muted" style="margin-top:6px">
                            <em>{l s='Draft needs human review' d='Modules.Daktela.Admin'}</em>
                        </div>
                    {/if}
                </td>
                <td>{$row.category|escape:'html':'UTF-8'}</td>
                <td>{$row.sentiment|escape:'html':'UTF-8'}</td>
                <td>{$row.urgency|escape:'html':'UTF-8'}</td>
                <td>{$row.comp_value|intval}</td>
                <td>
                    {if $row.waiting}
                        <span class="label label-danger">{($row.wait_seconds/3600)|round}h</span>
                    {else}
                        <span class="text-muted">—</span>
                    {/if}
                </td>
                <td>
                    <form method="post" action="{$daktela_index|escape:'html':'UTF-8'}" class="form-inline">
                        <input type="hidden" name="token" value="{$daktela_token|escape:'html':'UTF-8'}">
                        <input type="hidden" name="id_daktela_ticket" value="{$row.id_daktela_ticket|intval}">
                        <input type="number" min="0" max="100" name="manual_score" class="form-control"
                               style="width:70px" value="{if $row.manual_score !== null}{$row.manual_score|intval}{/if}">
                        <button type="submit" name="submitManualScore" class="btn btn-default">{l s='Set' d='Modules.Daktela.Admin'}</button>
                    </form>
                </td>
            </tr>
        {/foreach}
        </tbody>
    </table>
    {/if}
</div>
