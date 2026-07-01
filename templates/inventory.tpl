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
            <th scope="col">Stability</th>
            <th scope="col">IP</th>
          </tr>
        </thead>
        {foreach $results as $key=>$value}
          {if isset($value.category)}
            <tr style="text-align: center; background-color: lightgrey;"><td colspan="5">{$value.category}</td><td style="opacity: 0.3">{if !isset($onecat)}<a href="?addcat"><img src="res/png/add.png"/></a>{/if}<a href="?delcat={$value.category_ip}"/><img src="res/png/delete.png"/></a></td></tr>
          {else if !isset($onecat)}
            <tr style="text-align: center; background-color: lightgrey;"><td colspan="5">&nbsp;</td><td><a href="?addcat"><img src="res/png/add.png"/></a></td></tr>
          {/if}
          {assign var="onecat" value="1"}
          {assign var="status" value=$value.status|default:""}
          {assign var="important" value=$value.important|default:""}
          {assign var="name" value=$value.name|default:""}
          {assign var="mac" value=$value.mac|default:""}
          {assign var="ip" value=$value.ip|default:""}
          <tr {if !($status == "Up") && $important == "1"}style="background: red;"{/if} osed="{$status}|{$important}">
            {if $status == "Down"}
              <td style="padding: 0;"><img src="res/png/bullet_ball_glass_red.png" style="height: 1em;" title="host down"/></td>
            {elseif $status == "arp-down"}
              <td style="padding: 0;"><img src="res/png/bullet_ball_glass_yellow.png" style="height: 1em;" title="host down, in arp cache"/></td>
            {elseif $status == "arp"}
              <td style="padding: 0;"><img src="res/png/bullet_ball_glass_blue.png" style="height: 1em;" title="arp up / ip down"/></td>
            {elseif $status == "Up"}
              <td>&nbsp;</td>
            {else}
              <td>{$status}</td>
            {/if}
            <td>
              {if isset($value.web) && $value.web == 1}
                <a href="http://{$ip}">{$name}</a>
              {else}
                {$name}
              {/if}
            </td>
            <td>
              {if isset($value.id)}
                <a href="?edit={$value.id}">{$mac|strtolower}{if isset($value.via)}<img src="res/png/antenna.png" title="{$value.via}"/>{/if}</a>
	              {else}
	                {$mac|strtolower}<a href="?create={$mac}"><img src="res/png/add.png"/></a>
	              {/if}
	            </td>
            <td>{$value.vendor}</td>
            <td>
              {if isset($value.stats2) && $value.stats2 > 1}
                <a href="?history={$ip}" title="{$value.stats|default:''}">{$value.stats2}</a>
              {/if}
            </td>
            <td>
              {$ip}
              {if isset($value.xml)}
                <a target="iframe" href="nmap/{$value.xml}.xml"><img src="res/png/information2.png"/></a>
              {/if}
            </td>
          </tr>
        {/foreach}
      </table>
    </td>
  </tr>
</table>
