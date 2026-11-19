<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<title>{{ config('app.name') }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<style type="text/css">
@media (prefers-color-scheme: dark) {
    .light-img {
        display: none !important;
        visibility: hidden !important;
        height: 0 !important;
        width: 0 !important;
        mso-hide: all !important;
    }
    .dark-img-wrapper,
    .dark-img {
        display: block !important;
        visibility: visible !important;
        overflow: visible !important;
        max-height: none !important;
        height: auto !important;
        width: 200px !important;
        margin: 0 auto !important;
    }
}

/* Suporte para Webmail / Outlook com data-ogsc */
[data-ogsc] .light-img {
    display: none !important;
}
[data-ogsc] .dark-img-wrapper,
[data-ogsc] .dark-img {
    display: block !important;
    width: 200px !important;
    height: auto !important;
    margin: 0 auto !important;
}
</style>
{!! $head ?? '' !!}
</head>
<body>

<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="wrapper-cell" align="center">
<table class="content" width="100%" cellpadding="0" cellspacing="0" role="presentation">
{!! $header ?? '' !!}

<!-- Email Body -->
<tr>
<td class="body" width="100%" cellpadding="0" cellspacing="0">
<table class="inner-body" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
<!-- Body content -->
<tr>
<td class="content-cell">
{!! Illuminate\Mail\Markdown::parse($slot) !!}

{!! $subcopy ?? '' !!}
</td>
</tr>
</table>
</td>
</tr>

{!! $footer ?? '' !!}
</table>
</td>
</tr>
</table>
</body>
</html>
