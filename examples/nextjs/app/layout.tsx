import { OknoBridge } from '@pixelersagency/okno-bridge/react';

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en">
      <body>
        {children}
        {/* Renders nothing. Downloads the bridge only inside Okno's editor. */}
        <OknoBridge wpOrigin={process.env.NEXT_PUBLIC_WORDPRESS_URL!} />
      </body>
    </html>
  );
}
