{*
 * TPV Connect - Back Office Header Injection
 * Adds an "Abrir TPV" button to the Back Office header when configured.
*}

{if isset($tpvconnect_sso_url) && $tpvconnect_sso_url}
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function() {
            var headerNav = document.querySelector('.header-right') || document.querySelector('#header-nav');
            if (headerNav) {
                var btn = document.createElement('a');
                btn.href = '{$tpvconnect_sso_url|escape:'javascript':'UTF-8'}';
                btn.target = '_blank';
                btn.className = 'btn btn-success btn-sm';
                btn.style.marginRight = '10px';
                btn.innerHTML = '<i class="icon-external-link"></i> {l s='Abrir TPV' mod='tpvconnect' js=1}';
                headerNav.insertBefore(btn, headerNav.firstChild);
            }
        });
    </script>
{/if}
