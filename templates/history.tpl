<table class="table table-dark table-sm">
    <thead>
        <tr>
            <th scope="col">MAC</th>
            <th scope="col">Status</th>
            <th scope="col">Date</th>
        </tr>
    </thead>
    {foreach $history as $key=>$value}
        {if {$value.duration} > 180}
            <tr {if !({$value.status} == "Up")}style="background: red;"{/if}>
        {else}
            <tr {if !({$value.status} == "Up")}style="color: #5555;"{/if}>
        {/if}
            <td>{$value.mac}</td>
            {if {$value.status} == "Down"}
                <td style="padding: 0;"><img src="res/png/bullet_ball_glass_red.png" style="height: 1em;" title="host down"/></td>
            {elseif {$value.status} == "arp-down"}
                <td style="padding: 0;"><img src="res/png/bullet_ball_glass_yellow.png" style="height: 1em;" title="host down, in arp cache"/></td>
            {elseif {$value.status} == "arp"}
                <td style="padding: 0;"><img src="res/png/bullet_ball_glass_blue.png" style="height: 1em;" title="arp up / ip down"/></td>
            {elseif {$value.status} == "Up"}
                <td>&nbsp;</td>
            {else}
                <td>{$value.status}</td>
            {/if}
            <td>{$value.date_begin} for {$value.duration|temps}</td>
        </tr>
    {/foreach}
</table>
