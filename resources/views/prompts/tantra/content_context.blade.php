{{-- tantra: standing context for the six Tantra profiles and the Tantra page (card 29, 2026-11-14). Source: tantra-enterprise-webinar deliverables/29-linkedin-profile-branding/v2/CONTENT_ENGINE_CONTEXT.md. Served to agents at GET /api/tantra/context. --}}
Standing context for Tantra content (the six team profiles and the Tantra page). Follow it for every post, carousel, first comment and giveaway.

Full pack with every field and image: https://adisuja.github.io/tantra-enterprise-webinar-pages/linkedin-profiles/v2/
Spec as markdown (headline, About, role, skills, Links table per person): https://adisuja.github.io/tantra-enterprise-webinar-pages/linkedin-profiles/v2/PROFILE_SPEC.md

1. Domains
- Organic traffic (LinkedIn profiles, posts, comments, giveaways, all content) goes to https://jointantra.com/ . Bonuses: https://bonuses.jointantra.com/ .
- Cold calendar traffic stays on https://webinar.sellwithtantra.com/ . Same site, same Worker, no redirect: one deploy updates both domains. Never use webinar.sellwithtantra.com in organic content.
- Consultation booking: https://crm.usetantra.com/book/tantra/ai-revops-strategy-consultation

2. Short links: every link in content is a tracked short link from the Tantra CRM (go.usetantra.com/l/<code>), created per piece in campaign "LinkedIn organic: team profiles" (cmp_f427ef8e47514047be1d, channel chn_c1118abc4dc74c06a10b for LinkedIn; add a channel per other platform). Code: <key>-<short-slug>, e.g. bhaskar-funnel-leaks. The short link carries the UTMs below. Exception: when the platform needs a link preview card (a link inside the post body on LinkedIn), use the full jointantra.com UTM link, because LinkedIn cannot preview go.usetantra.com yet.

   UTM rule behind every link (never drop or shorten the UTMs)
utm_source=linkedin & utm_campaign=enterprise-webinar & utm_medium=<post | comment | carousel | giveaway | company_page> & utm_content=<key>-<short-slug-of-the-piece>
Example: https://jointantra.com/?utm_source=linkedin&utm_medium=comment&utm_campaign=enterprise-webinar&utm_content=bhaskar-funnel-leaks-carousel
Profile short links already used (go.usetantra.com/l/<key>-fw, -fc, -ww, -wc, -aw, -ac, -rw, -rc; page tantra-aw, tantra-ac, tantra-btn), so do not reuse these codes or utm_content values: <key>-featured-workshop, <key>-featured-consultation, <key>-website-workshop, <key>-website-consultation, <key>-about-workshop, <key>-about-consultation, <key>-role-workshop, <key>-role-consultation; page: about-workshop, about-consultation, button.

3. The six profiles (title, headline, cover caption)
- Adi (key adi, linkedin.com/in/adisuja): Leading the team @ Tantra. Headline: Leading the team @ Tantra | AI RevOps that turns cold traffic into booked calls and closed revenue | 400+ brands, 21+ countries | Join my live AI RevOps workshop: link below 👇. Cover: Our AI Haunts Your Audience Until They Pay You.
- Bhaskar (key bhaskar, linkedin.com/in/bhaskar-dev): Leading marketing & product @ Tantra. Headline: Leading marketing & product @ Tantra | Marketing that books sales calls, not vanity leads | AI RevOps for B2B teams | Join our live AI RevOps workshop: link below 👇. Cover: Your Pipeline Called. It's Hungry.
- Aquila (key aquila, linkedin.com/in/aquilaeldho): Leading automations @ Tantra. Headline: Leading automations @ Tantra | I automate the reminders, follow ups and handoffs B2B teams still do by hand | AI RevOps | Live workshop: link below 👇. Cover: Leads Left on Read? Not on Our Watch.
- Bharath (key bharath, linkedin.com/in/bkp31415): Leading development @ Tantra. Headline: Leading development @ Tantra | The CRM, integrations and AI agents behind every Tantra funnel | AI RevOps for B2B teams | Live workshop: link below 👇. Cover: Your Pipeline Called. It's Hungry.
- Ben (key ben, linkedin.com/in/ben-basil-tomy-5533b9218): Leading development @ Tantra. Headline: Leading development @ Tantra | Pages, forms and tracking that turn clicks into booked calls | AI RevOps for B2B teams | Live workshop: link below 👇. Cover: Cold Leads? We Bring Blankets.
- Nelbin (key nelbin, linkedin.com/in/nelbin-xav): Leading marketing ops @ Tantra. Headline: Leading marketing ops @ Tantra | An owner, a checklist and a weekly number for every funnel stage | AI RevOps for B2B teams | Live workshop: link below 👇. Cover: Our AI Haunts Your Audience Until They Pay You.
- Tantra page (linkedin.com/company/tantraengine): tagline "AI revenue systems for B2B teams: the funnel, the outreach and the pipeline. See it live at our weekly webinar." Cover: Your Pipeline Called. It's Hungry. Logo: circuit tree over a glowing head on deep violet (logo D).

4. Caption test
Four cover captions run at once (Haunts: Adi, Nelbin; Pipeline: Bhaskar, Bharath, page; Left on Read: Aquila; Blankets: Ben). Match each person's post voice to their own cover line where it fits, and keep utm_content per person so results compare by caption.

5. Brand look for content images
Deep violet to black ground (#05060a, #2a1a7a, accent #8b6cff, gold bar #f5c76b), Inter, white text, client logos as white logos in glass chips, the circuit tree mark. Faces are each person's Kie.ai AI avatar; the branded profile photos are at https://adisuja.github.io/tantra-enterprise-webinar-pages/linkedin-profiles/v2/assets/<key>-photo-1200.png .

6. Every profile's Featured section has two items: workshop first, consultation second. Posts can say "link below 👇" or "in my Featured section" to point people there.

7. Links in the Engine
- Write organic links as https://jointantra.com/ (or the consultation page). When the piece is saved, the Engine replaces each link with a tracked go.usetantra.com short link made for that piece and person in the Tantra CRM, with the UTMs above. A link in a LinkedIn post body that shows a preview card keeps the full jointantra.com UTM link. Never type a go.usetantra.com code yourself.
- Never use em dashes or en dashes.
