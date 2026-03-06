#!/bin/bash

echo "🔨 Building frontend..."
npm run build

if [ $? -eq 0 ]; then
    echo "✅ Build successful!"
    echo ""
    echo "📦 Files generated in dist/:"
    ls -lh dist/
    echo ""
    echo "📄 Manifest contents:"
    cat dist/.vite/manifest.json
    echo ""
    echo "🎯 Next steps:"
    echo "1. Upload dist/ folder to your server"
    echo "2. Configure API_URL environment variable"
    echo "3. Test with: curl http://your-domain/your-slug | grep 'og:'"
    echo ""
    echo "📚 Read DEPLOYMENT_FRONTEND.md for detailed instructions"
else
    echo "❌ Build failed!"
    exit 1
fi
