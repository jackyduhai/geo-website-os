<input type="hidden" name="field[{{ $key }}]" value="0">
<input type="checkbox" name="field[{{ $key }}]" value="1"
       @checked(! empty($cfg[$key]))>
