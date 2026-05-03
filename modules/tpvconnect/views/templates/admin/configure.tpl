{*
 * TPV Connect - Admin Configuration Template
 * Copyright (c) VenPosTPV. All rights reserved.
*}

<div class="panel">
    <h3><i class="icon icon-credit-card"></i> {l s='TPV Connect' mod='tpvconnect'}</h3>
    <p>{l s='Configura la conexión entre PrestaShop y tu aplicación TPV independiente.' mod='tpvconnect'}</p>

    {if !$ws_enabled}
        <div class="alert alert-warning">
            <strong>{l s='¡Atención!' mod='tpvconnect'}</strong>
            {l s='El servicio web de PrestaShop está deshabilitado.' mod='tpvconnect'}
            <a href="{$admin_webservice_url|escape:'html':'UTF-8'}" class="alert-link">
                {l s='Haz clic aquí para activarlo' mod='tpvconnect'}
            </a>.
        </div>
    {/if}

    <form class="defaultForm form-horizontal" action="{$current|escape:'html':'UTF-8'}&amp;configure={$module_name|escape:'html':'UTF-8'}&amp;token={$token|escape:'html':'UTF-8'}" method="post" enctype="multipart/form-data">
        <div class="form-wrapper">
            <div class="form-group">
                <label class="control-label col-lg-3">{l s='URL del TPV' mod='tpvconnect'}</label>
                <div class="col-lg-6">
                    <input type="url" name="TPVCONNECT_URL" value="{$tpv_url|escape:'html':'UTF-8'}" class="form-control" placeholder="https://tpv.tudominio.com">
                    <p class="help-block">{l s='Debe comenzar con https://' mod='tpvconnect'}</p>
                </div>
            </div>

            <div class="form-group">
                <label class="control-label col-lg-3">{l s='Secreto SSO' mod='tpvconnect'}</label>
                <div class="col-lg-5">
                    <input type="password" name="TPVCONNECT_SSO_SECRET" id="tpv_sso_secret" value="{$sso_secret|escape:'html':'UTF-8'}" class="form-control" autocomplete="off">
                    <p class="help-block">{l s='Mínimo 32 caracteres. Se usa para firmar los tokens SSO y autenticar la API.' mod='tpvconnect'}</p>
                </div>
                <div class="col-lg-1">
                    <button type="button" class="btn btn-default" onclick="var f=document.getElementById('tpv_sso_secret');f.type=f.type==='password'?'text':'password';">
                        <i class="icon icon-eye"></i>
                    </button>
                </div>
                <div class="col-lg-3">
                    <button type="submit" name="submit_tpvconnect_regenerate_secret" class="btn btn-warning">
                        <i class="icon icon-refresh"></i> {l s='Regenerar' mod='tpvconnect'}
                    </button>
                </div>
            </div>

            <div class="form-group">
                <label class="control-label col-lg-3">{l s='Abrir TPV' mod='tpvconnect'}</label>
                <div class="col-lg-6">
                    {if $sso_url}
                        <a href="{$sso_url|escape:'html':'UTF-8'}" target="_blank" class="btn btn-success">
                            <i class="icon icon-external-link"></i> {l s='Abrir TPV' mod='tpvconnect'}
                        </a>
                        <p class="help-block">{l s='Se abrirá en una pestaña nueva con autenticación SSO.' mod='tpvconnect'}</p>
                    {else}
                        <button type="button" class="btn btn-success" disabled>
                            <i class="icon icon-external-link"></i> {l s='Abrir TPV' mod='tpvconnect'}
                        </button>
                        <p class="help-block text-danger">{l s='Configura primero la URL del TPV y el secreto SSO.' mod='tpvconnect'}</p>
                    {/if}
                </div>
            </div>

            <div class="form-group">
                <label class="control-label col-lg-3">{l s='Clave de API (Webservice)' mod='tpvconnect'}</label>
                <div class="col-lg-6">
                    {if $ws_key_id > 0}
                        <p class="form-control-static">
                            <span class="label label-success">{l s='Activa' mod='tpvconnect'}</span>
                            {l s='ID de clave:' mod='tpvconnect'} {$ws_key_id|intval}
                        </p>
                    {else}
                        <p class="form-control-static">
                            <span class="label label-default">{l s='No generada' mod='tpvconnect'}</span>
                        </p>
                    {/if}
                </div>
                <div class="col-lg-3">
                    <button type="submit" name="submit_tpvconnect_generate_ws_key" class="btn btn-primary" {if !$ws_enabled}disabled{/if}>
                        <i class="icon icon-key"></i> {l s='Generar clave API' mod='tpvconnect'}
                    </button>
                </div>
            </div>
        </div>

        <div class="panel-footer">
            <button type="submit" name="submit_tpvconnect_config" class="btn btn-default pull-right">
                <i class="process-icon-save"></i> {l s='Guardar' mod='tpvconnect'}
            </button>
        </div>
    </form>
</div>
