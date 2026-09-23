<select name="field[{{ $key }}]" class="select">
  @foreach($f['options'] as $opt)
    <option value="{{ $opt }}"
      @selected((string) ($cfg[$key] ?? '') === (string) $opt)>{{ $opt }}</option>
  @endforeach
</select>
