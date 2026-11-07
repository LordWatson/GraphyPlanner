# Google Play listing (Step GTM-6.1)

Everything the Play Console asks for, written down so the listing is a copy-paste job rather than a
drafting session, and so what we declare stays reviewable in git.

**Unverified claims are the risk here.** A store listing is a public promise: nothing below says we
do something we don't, and nothing in the data-safety section under-declares. If a feature is
removed from the app, this file changes with it.

## Store listing

**App name** (30 max): `Alima — Social Media Planner`

**Short description** (80 max):

> Plan, approve and publish your social media from one place.

**Full description** (4000 max):

> Alima is where a brand's social media gets planned, approved and published — on a phone, without
> a laptop open.
>
> **Plan a week in minutes.** A calendar that shows what is going out and what is still an idea.
> Write once and adapt per network, with character limits and format rules checked before anything
> is scheduled.
>
> **Publish to nine networks.** Instagram, Facebook, TikTok, LinkedIn, X, Pinterest, YouTube,
> Threads and Google Business Profile, connected in a tap.
>
> **Reply where the conversation is.** Comments from every connected account in one inbox, with
> saved replies for the things you answer every week.
>
> **See what worked.** Reach, interactions and your best times to post, in plain language rather
> than a wall of charts.
>
> **A link in bio you own.** Your links, your latest posts, your media kit and, on Creator Pro, a
> store that pays you directly.
>
> **AI that knows your brand.** Caption suggestions, rewrites and weekly ideas grounded in your own
> brand voice — always a suggestion, never posted for you.
>
> An Alima account is required. A free plan covers one brand, three connected accounts and 30
> scheduled posts a month.

**Category**: Business · **Tags**: Social, Marketing · **Content rating**: Everyone

**Contact**: `support@alima.com` · **Privacy policy**: `https://app.alima.com/privacy`

## Graphics to produce

| Asset              | Spec                  | Source                                                                   |
| ------------------ | --------------------- | ------------------------------------------------------------------------ |
| App icon           | 512×512 PNG, no alpha | `public/icons/icon-512.png`                                              |
| Feature graphic    | 1024×500 PNG          | **to produce** — brand guidelines §3, Ink on Cream, no screenshots in it |
| Phone screenshots  | 2–8, 1080×1920        | **to produce** — Today, Calendar, the post editor, Inbox, Insights       |
| Tablet screenshots | optional              | skip for the first release                                               |

## Data safety declaration

What the app actually collects, matched to what the code does. Everything is in transit over TLS and
a user can request deletion.

| Data type                                        | Collected | Shared  | Why                                                                         | Optional |
| ------------------------------------------------ | --------- | ------- | --------------------------------------------------------------------------- | -------- |
| Name, email address                              | Yes       | No      | Account and sign-in                                                         | No       |
| Photos and videos                                | Yes       | **Yes** | The media you attach is sent to the networks you publish to                 | No       |
| Other user content (captions, comments, replies) | Yes       | **Yes** | Published to the networks you choose                                        | No       |
| App interactions (analytics)                     | Yes       | No      | Product analytics and error reports                                         | No       |
| Crash logs and diagnostics                       | Yes       | No      | Error tracking                                                              | No       |
| Approximate location                             | No        | —       | —                                                                           | —        |
| Precise location                                 | No        | —       | —                                                                           | —        |
| Financial info                                   | No        | —       | Card details are entered on Stripe's own hosted pages and never reach Alima | —        |
| Contacts                                         | No        | —       | —                                                                           | —        |

Answers to the three questions people get wrong:

- **Is data encrypted in transit?** Yes.
- **Can a user request deletion?** Yes — in the app, and by emailing support.
- **Is any data shared with third parties?** Yes, and it must be declared: media and captions go to
  the social networks the user connects, and to the publishing vendor that delivers them on the
  user's behalf.

## Account deletion (a listing requirement)

Play requires a **web** URL where an account can be deleted without installing the app. Point this
at the in-app deletion page; it already exists (`settings/profile` → delete account).

> `https://app.alima.com/settings/profile`

## Release checklist

- [ ] `ANDROID_PACKAGE` and `ANDROID_FINGERPRINTS` set in production, `/.well-known/assetlinks.json` returns JSON
- [ ] Installed from Play shows **no address bar** (otherwise verification has failed)
- [ ] Push notification arrives on a real device from the installed app
- [ ] Privacy policy and account-deletion URLs both resolve
- [ ] Data safety form matches the table above
- [ ] First review date for the listing copy set alongside the GTM-7.2 competitor review
