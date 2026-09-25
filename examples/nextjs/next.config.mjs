/** @type {import('next').NextConfig} */
const nextConfig = {
  async headers() {
    return [
      {
        source: '/:path*',
        headers: [
          {
            // Lets wp-admin display the site in Okno's editor, and nobody else.
            key: 'Content-Security-Policy',
            value: `frame-ancestors 'self' ${process.env.WORDPRESS_URL}`,
          },
        ],
      },
    ];
  },
};

export default nextConfig;
