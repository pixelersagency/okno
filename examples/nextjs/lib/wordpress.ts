const WP = process.env.WORDPRESS_URL!;

export type Section =
  | { acf_fc_layout: 'hero'; title: string; image: { url: string; alt: string } | false }
  | { acf_fc_layout: 'text'; body: string };

export type Page = {
  id: number;
  title: { rendered: string };
  acf: {
    intro: string;
    sections: Section[] | false;
  };
};

export async function getPage(slug: string): Promise<Page | null> {
  const url = slug
    ? `${WP}/wp-json/wp/v2/pages?slug=${encodeURIComponent(slug)}&acf_format=standard`
    : `${WP}/wp-json/wp/v2/pages?slug=home&acf_format=standard`;

  const res = await fetch(url, { cache: 'no-store' });
  if (!res.ok) return null;
  const pages: Page[] = await res.json();
  return pages[0] ?? null;
}
