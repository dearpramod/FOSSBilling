<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace FOSSBilling;

class ErrorPage
{
    /**
     * Returns the list of error codes and their specialized messages. All Error code parameters are optional.
     */
    private static function getCodes(): array
    {
        return [
            '1' => [
                'title' => 'Unable to find Composer Packages',
                'message' => 'The composer packages appear to be missing. This shouldn\'t happen if you are using a release version of FOSSBilling. If you are developer, you will need to install dependencies using <code>composer install</code>.',
                'link' => [
                    'label' => 'View more info on the composer website',
                    'href' => 'https://getcomposer.org/doc/01-basic-usage.md#installing-dependencies',
                ],
                'report' => false,
            ],
            '3' => [
                'title' => 'Your Configuration is Empty',
                'message' => 'Your FOSSBilling configuration seems to either be empty or non-existent. You may need to re-install FOSSBilling, or re-create the <code>config.php</code> file based on the example config.',
                'link' => [
                    'label' => 'See the example config.',
                    'href' => 'https://github.com/FOSSBilling/FOSSBilling/blob/main/src/config-sample.php',
                ],
                'report' => false,
            ],
            '5' => [
                'title' => 'Missing .htaccess file',
                'message' => 'You appear to be running an Apache or LiteSpeed based webserver without a valid <b><em>.htaccess</em></b> file. Please create one using the default FOSSBilling .htaccess file.',
                'link' => [
                    'label' => 'Check the default .htaccess',
                    'href' => 'https://github.com/FOSSBilling/FOSSBilling/blob/main/src/.htaccess',
                ],
                'report' => false,
            ],
            // Incomplete server manager configuration. Is listed here so it's not forwarded to Sentry.io
            2001 => [
                'report' => false,
            ],
            // Incomplete registrar configuration. Is listed here so it's not forwarded to Sentry.io
            3001 => [
                'report' => false,
            ],
            // Incomplete payment gateway configuration. Is listed here so it's not forwarded to Sentry.io
            4001 => [
                'report' => false,
            ],
        ];
    }

    /* List of code categories. The "start" and "end" values are considered valid for a category.
     * (Example: an error code of 50 will match the "FOSSBilling Loader" category)
     */
    private static array $codeCategories = [
        'FOSSBilling Loader' => [
            'start' => 1,
            'end' => 50,
        ],
        'HTTP Error Codes' => [
            'start' => 400,
            'end' => 599,
        ],
        'Server Managers' => [
            'start' => 2000,
            'end' => 2999,
        ],
        'Domain Registration' => [
            'start' => 3000,
            'end' => 3999,
        ],
        'Payment Gateway' => [
            'start' => 4000,
            'end' => 4999,
        ],
    ];

    /**
     * Gets info for a specified error code, using placeholders for anything undefined.
     *
     * @param int $code The error code
     */
    public static function getCodeInfo(int|string $code): array
    {
        $code = intval($code);
        $errorDetails = [
            'title' => 'An error has occurred.',
            'link' => [
                'label' => 'View the FOSSBilling documentation',
                'href' => 'https://fossbilling.org/docs',
            ],
            'category' => 'None',
            'report' => true,
        ];

        $codes = self::getCodes();

        if (key_exists($code, $codes)) {
            $codeInfo = $codes[$code];
            $errorDetails = array_merge($errorDetails, $codeInfo);
        }

        $errorDetails['category'] = 'Generic';
        foreach (self::$codeCategories as $categoryName => $categoryRange) {
            if ($code >= $categoryRange['start'] && $code <= $categoryRange['end']) {
                $errorDetails['category'] = $categoryName;

                break;
            }
        }

        return $errorDetails;
    }

    /**
     * @param int    $code    Error code
     * @param string $message The original exception message
     */
    public function renderPage(int $code, string $message): string
    {
        $error = static::getCodeInfo($code);
        $error['message'] ??= htmlspecialchars($message);

        $is404 = $code === 404;
        $is5xx = $code >= 500 && $code <= 599;
        $is403 = $code === 403;

        if ($is404) {
            $bigLabel = '404';
            $heading = 'Page Not Found';
            $subheading = "The page you're looking for doesn't exist or may have been moved.";
            $iconPath = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>';
        } elseif ($is403) {
            $bigLabel = '403';
            $heading = 'Access Denied';
            $subheading = "You don't have permission to access this resource.";
            $iconPath = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>';
        } elseif ($is5xx) {
            $bigLabel = (string) $code;
            $heading = 'Something Went Wrong';
            $subheading = 'An unexpected error occurred. Please try again or contact support.';
            $iconPath = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>';
        } else {
            $bigLabel = $code > 0 ? (string) $code : '!';
            $heading = $error['title'];
            $subheading = $error['message'];
            $iconPath = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>';
        }

        $safeHeading = htmlspecialchars((string) $heading);
        $safeSubheading = htmlspecialchars($subheading);
        $safeMessage = htmlspecialchars($message);
        $safeLabel = htmlspecialchars($bigLabel);
        $safeDocLink = htmlspecialchars($error['link']['href'] ?? 'https://fossbilling.org/docs');
        $safeDocLabel = htmlspecialchars($error['link']['label'] ?? 'View documentation');
        $safeCategory = htmlspecialchars($error['category'] ?? '');

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en" data-theme="dark">
            <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>{$safeHeading} &mdash; MeroVPS</title>
            <style>
            *,::after,::before{box-sizing:border-box;margin:0;padding:0}
            html,body{height:100%;-webkit-font-smoothing:antialiased}
            body{
              font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
              background:#0b0f19;color:#e2e8f0;display:flex;flex-direction:column;
              align-items:center;justify-content:center;min-height:100vh;padding:24px;
              background-image:radial-gradient(ellipse 80% 50% at 50% -10%,rgba(99,102,241,.18),transparent);
            }
            a{color:inherit;text-decoration:none}

            /* animated scan line */
            @keyframes scan{0%{top:0}100%{top:100%}}
            @keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-8px)}}

            .card{
              width:100%;max-width:520px;background:rgba(255,255,255,.04);
              border:1px solid rgba(255,255,255,.08);border-radius:24px;
              padding:48px 40px 36px;text-align:center;
              box-shadow:0 0 80px rgba(99,102,241,.08),0 24px 48px rgba(0,0,0,.4);
            }

            .icon-wrap{
              position:relative;display:inline-flex;align-items:center;justify-content:center;
              width:120px;height:120px;border-radius:20px;margin:0 auto 28px;
              background:rgba(99,102,241,.08);border:1px solid rgba(99,102,241,.18);
              overflow:hidden;animation:float 4s ease-in-out infinite;
            }
            .scan-line{
              position:absolute;left:0;right:0;height:2px;
              background:linear-gradient(90deg,transparent,rgba(99,102,241,.6),transparent);
              animation:scan 3s linear infinite;
            }
            .corner{position:absolute;width:14px;height:14px;border-color:rgba(99,102,241,.4);border-style:solid;}
            .c-tl{top:8px;left:8px;border-width:2px 0 0 2px;border-radius:4px 0 0 0}
            .c-tr{top:8px;right:8px;border-width:2px 2px 0 0;border-radius:0 4px 0 0}
            .c-bl{bottom:8px;left:8px;border-width:0 0 2px 2px;border-radius:0 0 0 4px}
            .c-br{bottom:8px;right:8px;border-width:0 2px 2px 0;border-radius:0 0 4px 0}

            .code-num{
              font-size:3rem;font-weight:800;line-height:1;letter-spacing:-.04em;
              background:linear-gradient(135deg,#6366f1,#a855f7);
              -webkit-background-clip:text;-webkit-text-fill-color:transparent;
              background-clip:text;
            }
            .code-badge{
              font-size:10px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;
              color:#6366f1;margin-top:6px;opacity:.8;
            }

            h1{font-size:1.65rem;font-weight:700;color:#f1f5f9;margin-bottom:10px;letter-spacing:-.02em}
            p.sub{font-size:.95rem;color:#94a3b8;line-height:1.7;margin-bottom:28px}

            .actions{display:flex;flex-wrap:wrap;gap:10px;justify-content:center;margin-bottom:28px}
            .btn{
              display:inline-flex;align-items:center;gap:6px;
              padding:9px 20px;border-radius:10px;font-size:.85rem;font-weight:600;
              cursor:pointer;transition:opacity .15s,transform .15s;white-space:nowrap;
            }
            .btn:hover{opacity:.88;transform:translateY(-1px)}
            .btn-primary{
              background:linear-gradient(135deg,#6366f1,#a855f7);color:#fff;border:none;
              box-shadow:0 4px 16px rgba(99,102,241,.35);
            }
            .btn-ghost{
              background:rgba(255,255,255,.05);color:#cbd5e1;
              border:1px solid rgba(255,255,255,.1);
            }

            .shortcuts{
              display:grid;grid-template-columns:repeat(4,1fr);gap:8px;
              border-top:1px solid rgba(255,255,255,.07);padding-top:20px;
            }
            .sc{
              display:flex;flex-direction:column;align-items:center;gap:6px;
              padding:10px 4px;border-radius:10px;cursor:pointer;
              transition:background .15s;color:#94a3b8;font-size:.72rem;font-weight:500;
            }
            .sc:hover{background:rgba(255,255,255,.06);color:#e2e8f0}
            .sc-icon{
              width:30px;height:30px;border-radius:7px;display:flex;align-items:center;justify-content:center;
              font-size:13px;
            }

            .meta{
              font-size:.72rem;color:#475569;margin-top:18px;
              display:flex;align-items:center;justify-content:center;gap:12px;flex-wrap:wrap;
            }
            .meta span{display:inline-flex;align-items:center;gap:4px}
            .dot{width:3px;height:3px;border-radius:50%;background:#334155;display:inline-block}

            /* detail reveal */
            .detail-toggle{
              font-size:.75rem;color:#475569;cursor:pointer;background:none;border:none;
              padding:4px 8px;border-radius:6px;transition:color .15s;
            }
            .detail-toggle:hover{color:#94a3b8}
            .detail-box{
              display:none;margin-top:10px;padding:10px 14px;border-radius:8px;
              background:rgba(0,0,0,.3);border:1px solid rgba(255,255,255,.07);
              font-size:.78rem;color:#64748b;text-align:left;word-break:break-word;line-height:1.6;
            }
            </style>
            </head>
            <body>

            <div class="card">

              <!-- animated icon box -->
              <div class="icon-wrap">
                <div class="scan-line"></div>
                <div class="corner c-tl"></div><div class="corner c-tr"></div>
                <div class="corner c-bl"></div><div class="corner c-br"></div>
                <div>
                  <div class="code-num">{$safeLabel}</div>
                  <div class="code-badge">{$safeCategory}</div>
                </div>
              </div>

              <h1>{$safeHeading}</h1>
              <p class="sub">{$safeSubheading}</p>

              <div class="actions">
                <a class="btn btn-primary" href="/">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                  Go Home
                </a>
                <button class="btn btn-ghost" onclick="history.back()">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                  Go Back
                </button>
                <a class="btn btn-ghost" href="/support">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 10h.01M12 10h.01M16 10h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                  Support
                </a>
              </div>

              <!-- shortcut grid -->
              <div class="shortcuts">
                <a class="sc" href="/">
                  <div class="sc-icon" style="background:rgba(99,102,241,.12)">🏠</div>Home
                </a>
                <a class="sc" href="/order">
                  <div class="sc-icon" style="background:rgba(139,92,246,.12)">🛒</div>Order
                </a>
                <a class="sc" href="/support">
                  <div class="sc-icon" style="background:rgba(16,185,129,.12)">💬</div>Support
                </a>
                <a class="sc" href="/login">
                  <div class="sc-icon" style="background:rgba(14,165,233,.12)">🔑</div>Login
                </a>
              </div>

              <!-- technical details (collapsed) -->
              <div class="meta">
                <button class="detail-toggle" onclick="document.getElementById('detail').style.display=document.getElementById('detail').style.display==='none'?'block':'none'">
                  Show technical details
                </button>
              </div>
              <div class="detail-box" id="detail">
                <strong>Error {$safeLabel}</strong> &mdash; {$safeCategory}<br>
                {$safeMessage}
              </div>

            </div>

            </body>
            </html>
            HTML;
    }
}
