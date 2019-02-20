<a href="?">back</a><br/>
Edit {$edit}:<br/>
<form action="?" method="post">
  <input type="hidden" name="edit2" value="{$edit2}">
  IP: {$network}.<input type="input" name="ip" value="{$num}"><br/>
  MAC: <input type="input" name="mac" value="{$mac}"><br/>
  Name: <input type="input" name="name" value="{$name}"><br/>
  Important: <input type="checkbox" name="important" {if {$important} == "1"}checked{/if}><br/>
  Router/repeater: <input type="checkbox" name="repeater" {if {$repeater} == "1"}checked{/if}><br/>
  Password: <input type="password" name="p" value=""><br/>
  <button type="submit" class="btn btn-primary mb-2">Confirm</button>
  <a href="?del={$id}">delete</a>
</form>
