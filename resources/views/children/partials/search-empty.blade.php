{{-- The search box filters the rows in the browser, so the server's own
     empty state never sees it: without this row a search that finds nobody
     leaves a blank table, which reads as "the page is broken". $searchHays is
     every row's search text keyed by LAN, the same strings the rows test. --}}
@if(! empty($searchHays))
    <tr x-show="search.trim() && ! Object.entries(@js($searchHays)).some(([lan, hay]) => ! gone.includes(lan) && matches(hay))" x-cloak data-search-empty>
        <td colspan="{{ $colspan }}" style="padding: 48px 12px; text-align: center;" class="muted">Nobody matches that search.</td>
    </tr>
@endif
