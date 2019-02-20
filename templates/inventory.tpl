<table>
  <tr>
    <td>
      <table class="table table-dark table-sm">
        <thead>
          <tr>
            <th scope="col">&nbsp;</th>
            <th scope="col">Name</th>
            <th scope="col">MAC</th>
            <th scope="col">Vendor</th>
            <th scope="col">IP</th>
          </tr>
        </thead>
        {foreach $results as $key=>$value}
          {if isset($value.category)}
            <tr style="text-align: center; background-color: lightgrey;"><td colspan="4">{$value.category}</td><td style="opacity: 0.3">{if !isset($onecat)}<a href="?addcat"><img src="res/png/add.png"/></a>{/if}<a href="?delcat={$value.category_ip}"/><img src="res/png/delete.png"/></a></td></tr>
          {else if !isset($onecat)}
            <tr style="text-align: center; background-color: lightgrey;"><td colspan="4">&nbsp;</td><td><a href="?addcat"><img src="res/png/add.png"/></a></td></tr>
          {/if}
          {assign var="onecat" value="1"}
          <tr {if !({$value.status} == "Up") && {$value.important} == "1"}style="background: red;"{/if} osed="{$value.status}|{$value.important}">
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
            <td>{$value.name}</td>
            <td>{$value.mac|strtolower}{if isset($value.via)}<img src="res/png/antenna.png" title="{$value.via}"/>{/if}</td>
            <td>{$value.vendor}</td>
            <td>
              <a href="?edit={$value.ip}&id={$value.id}&mac={$value.mac}">{$value.ip}</a>
              {if isset($value.xml)}
                <a target="iframe" href="nmap/{$value.xml}.xml"><img src="res/png/information2.png"/></a>
              {/if}
            </td>
          </tr>
        {/foreach}
      </table>
    </td>
    <td>
      <iframe name="iframe" width="600" height="900" src="about:blank"></iframe>
    </td>
  </tr>
</table>