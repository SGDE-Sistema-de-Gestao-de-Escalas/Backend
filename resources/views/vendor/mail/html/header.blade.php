@props(['url'])
<tr>
<td class="header" align="center">
<a href="{{ $url }}" class="header-link" target="_blank" rel="noopener" style="display: inline-block; text-decoration: none;">
    <!-- Logótipo para modo claro (Light Mode) -->
    <img src="cid:sgde-logo-light" class="header-logo light-img" width="200" height="67" alt="SGDE - Sistema de Gestão de Escalas" border="0" style="display: block; width: 200px; max-width: 200px; height: auto; border: 0; margin: 0 auto;" />
    <!-- Logótipo para modo escuro (Dark Mode) -->
    <!--[if !mso]><!-->
    <div class="dark-img-wrapper" style="display: none; overflow: hidden; mso-hide: all;">
        <img src="cid:sgde-logo-dark" class="header-logo dark-img" width="200" height="67" alt="SGDE - Sistema de Gestão de Escalas" border="0" style="display: none; width: 200px; max-width: 200px; height: auto; border: 0; margin: 0 auto;" />
    </div>
    <!--<![endif]-->
</a>
</td>
</tr>
