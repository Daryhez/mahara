{if $icon}<div id="profile-icon">{$icon}</div>{/if}
<div id="profile-introduction">{$introduction}</div>
<ul id="profile-links">
{if $profileviewexported}    <li><a href="files/internal/profilepage.html">{str tag=viewprofilepage section=artefact.internal}</a></li>{/if}
    <li><a href="files/internal/index.html">{str tag=viewallprofileinformation section=artefact.internal}</a></li>
</ul>
<div class="cb"></div>