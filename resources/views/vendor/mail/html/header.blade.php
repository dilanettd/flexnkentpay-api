@props(['url'])
<tr>
    <td class="header">
        <a href="{{ $url }}" style="display: inline-block;">
            @if (trim($slot) === 'FlexnkentPay')
                <img src="https://getlogovector.com/wp-content/uploads/2020/11/toss-payments-logo-vector.png" class="logo"
                    alt="Logo">
            @else
                {{ $slot }}
            @endif
        </a>
    </td>
</tr>