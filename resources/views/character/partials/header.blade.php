<ul class="no-bullet no-indent">
    @if (isset($character->pivot->created_at))
    <li>
        <ul class="list-inline">
            <li class="list-inline-item text-muted small">
                  Recieved: <span class="js-watchable-timestamp js-timestamp-title" data-timestamp="{{ $character->pivot->created_at }}"></span> ago
            </li>
        </ul>
    </li>
    @endif
    <li>
        <ul class="list-inline">
            @if (isset($showIcon) && $showIcon && $character->class)
                <li class="list-inline-item">
                    <img src="{{ asset('images/' . $character->class . '.jpg') }}" class="class-icon" />
                </li>
            @endif
            <li class="list-inline-item">
                <h{{ isset($headerSize) && $headerSize ? $headerSize : '2' }} class="font-weight-bold">
                    {{ isset($titlePrefix) && $titlePrefix ? $titlePrefix : '' }}<a href="{{route('character.show', ['guildSlug' => $guild->slug, 'name' => $character->name]) }}" class="text-{{ $character->class ? strtolower($character->class) : '' }}">{{ $character->name }}</a>{{ isset($titleSuffix) && $titleSuffix ? $titleSuffix : '' }}
                </h{{ isset($headerSize) && $headerSize ? $headerSize : '2' }}>
            </li>
            @if (isset($showEdit) && $showEdit)
                <li class="list-inline-item">
                    <a href="{{ route('character.edit', ['guildSlug' => $guild->slug, 'name' => $character->name]) }}">
                        <span class="fas fa-fw fa-pencil"></span>
                        edit
                    </a>
                </li>
            @endif
            @if (isset($showEditLoot) && $showEditLoot)
                <li class="list-inline-item">
                    <a href="{{ route('character.loot', ['guildSlug' => $guild->slug, 'name' => $character->name]) }}">
                        <span class="fas fa-fw fa-sack"></span>
                        loot
                    </a>
                </li>
            @endif
        </ul>
    </li>
    @if ($character->raid_id || $character->class)
        <li>
            <ul class="list-inline">
                {{-- Don't let this get lazy loaded on its own; force the dev to do it intentionally to avoid poor performance --}}
                @if ($character->relationLoaded('raid') && $character->raid)
                    @php
                        $raidColor = null;
                        if ($character->raid->relationLoaded('role')) {
                            $raidColor = $character->raid->getColor();
                        }
                    @endphp
                    <li class="list-inline-item font-weight-bold">
                        <span class="tag d-inline" style="border-color:{{ $raidColor }};"><span class="role-circle" style="background-color:{{ $raidColor }}"></span>
                            {{ $character->raid->name }}
                        </span>
                    </li>
                @elseif ($character->raid_name)
                    <li class="list-inline-item font-weight-bold">
                        <span class="tag d-inline" style="border-color:{{ $character->raid_color ? getHexColorFromDec($character->raid_color) : '' }};"><span class="role-circle" style="background-color:{{ $character->raid_color ? getHexColorFromDec($character->raid_color) : '' }}"></span>
                            {{ $character->raid_name }}
                        </span>
                    </li>
                @endif
                <li class="list-inline-item">
                    {{ $character->class ? $character->class : '' }}
                </li>
            </ul>
        </li>
    @endif

    @if (!isset($showDetails) || $showDetails)
        @if ($character->inactive_at || $character->level || $character->race || $character->spec)
            <li>
                <small>
                    <span class="font-weight-bold text-danger">{{ $character->inactive_at ? 'INACTIVE' : '' }}</span>
                    {{ $character->level ? $character->level : '' }}
                    {{ $character->race  ? $character->race : '' }}
                    {{ $character->spec  ? $character->spec : '' }}
                </small>
            </li>
        @endif

        @if ($character->rank || $character->profession_1 || $character->profession_2)
            <li>
                <small>
                    {{ $character->rank         ? 'Rank ' . $character->rank . ($character->profession_1 || $character->profession_2 ? ',' : '') : '' }}
                    {{ $character->profession_1 ? $character->profession_1 . ($character->profession_2 ? ',' : '') : '' }}
                    {{ $character->profession_2 ? $character->profession_2 : ''}}
                </small>
            </li>
        @endif
    @endif

    @if (!isset($showOwner) || (isset($showOwner) && $showOwner))
        <li>
            <small>
                @if ($character->member_id)
                    {{-- Don't let this get lazy loaded on its own; force the dev to do it intentionally to avoid poor performance --}}
                    @if ($character->relationLoaded('member'))
                        <a href="{{route('member.show', ['guildSlug' => $guild->slug, 'username' => $character->member->username]) }}" class="">
                            {{ $character->member->username }}'s character
                        </a>
                    @endif
                @else
                    Unclaimed
                @endif
            </small>
        </li>
    @endif
</ul>
