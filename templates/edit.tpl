<a href="?">back</a><br/>
Edit ID {$edit}:<br/>
<form action="?" method="post">
  <input type="hidden" name="edit2" value="{$edit}">
  IP: {$network}.<input type="input" name="ip" value="{$num}"><br/>
  Router: {$network}.<input type="input" name="router" value="{$router}"><br/>
  MAC: <input type="input" name="mac" value="{$mac}"><br/>
  Name: <input type="input" name="name" value="{$name}"><br/>
  Important: <input type="checkbox" name="important" {if {$important} == "1"}checked{/if}><br/>
  Router/repeater: <input type="checkbox" name="repeater" {if {$repeater} == "1"}checked{/if}><br/>
  DNS: <input type="input" name="dns" value="{$dns}"><br/>
  Web: <input type="checkbox" name="web" {if {$web} == "1"}checked{/if}><br/>
  Password: <input type="password" name="p" value=""><br/>
  <button type="submit" class="btn btn-primary mb-2">Confirm</button>
  <a href="?del={$edit}">delete</a>
</form>
