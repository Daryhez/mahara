<div class="pageactions" id="toolbar-buttons">
    <div class="btn-group-vertical in-editor">
    {if $ineditor}
        {include file="view/contenteditor.tpl" selected='content'}
        <span>&nbsp;</span>
    {/if}
    {if ($edittitle || $canuseskins) }
        <a class="btn btn-secondary first-of-group editviews editlayout {if $selected == 'editlayout'}active{/if}"
            href="{$WWWROOT}view/editlayout.php?id={$viewid}"
            title="{str tag=settings section=view}">
            <span class="icon icon-cogs icon-lg"></span>
            <span class="btn-title sr-only">{str tag=settings section=view}</span>
        </a>
    {/if}
    {if $selected == 'content'}
        {if $viewurl}
            <a id='displaypagebtn' class="btn btn-secondary editviews displaycontent" href="{$WWWROOT}{if $collectionurl}{$collectionurl}{else}view/view.php?id={$viewid}{/if}" title="{str tag=displayview section=view}">
                <span class="icon icon-tv icon-lg" aria-hidden="true" role="presentation"></span>
                <span class="btn-title sr-only">{str tag=displayview section=view}</span>
            </a>
        {/if}
    {else}
        <a class="btn btn-secondary editviews editcontent {if $selected == 'content'}active{/if}" href="{$WWWROOT}{if $collectionurl}{$collectionurl}{else}view/blocks.php?id={$viewid}{/if}" title="{str tag=editcontent1 section=view}">
            <span class="icon icon-pencil-alt icon-lg" aria-hidden="true" role="presentation"></span>
            <span class="btn-title sr-only">{str tag=editcontent1 section=view}</span>
        </a>
    {/if}
    {if !$accesssuspended && ($edittitle || $viewtype == 'share') && !$issitetemplate}
        <a class="btn btn-secondary editviews editshare {if $selected == 'share'}active{/if}" href="{$WWWROOT}view/accessurl.php?id={$viewid}{if $collectionid}&collection={$collectionid}{/if}"  title="{str tag=shareview1 section=view}">
            <span class="icon icon-unlock icon-lg" aria-hidden="true" role="presentation"></span>
            <span class="btn-title sr-only">{str tag=shareview1 section=view}</span>
        </a>
    {/if}

    <a class="btn btn-secondary editviews returntolocation"
        href={$url}
        title="{$title}">
        <span class="icon icon-step-backward icon-lg" aria-hidden="true" role="presentation"></span>
        <span class="btn-title sr-only">{$title}</span>
    </a>
    </div>
</div>
