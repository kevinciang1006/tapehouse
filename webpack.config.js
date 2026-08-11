const path = require('path');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');
const CssMinimizerPlugin = require('css-minimizer-webpack-plugin');
const { WebpackManifestPlugin } = require('webpack-manifest-plugin');

module.exports = (env, argv) => {
    const isProduction = argv.mode === 'production';

    return {
        entry: { app: './resources/js/app.js' },
        output: {
            path: path.resolve(__dirname, 'public/build'),
            // Content hashing is what lets the Blade helper emit a cache-busted
            // URL without a version query string.
            filename: isProduction ? '[name].[contenthash].js' : '[name].js',
            publicPath: '/build/',
            clean: true,
        },
        module: {
            rules: [
                {
                    test: /\.js$/,
                    exclude: /node_modules/,
                    use: {
                        loader: 'babel-loader',
                        options: { presets: [['@babel/preset-env', { targets: 'last 2 versions' }]] },
                    },
                },
                {
                    test: /\.scss$/,
                    use: [MiniCssExtractPlugin.loader, 'css-loader', 'sass-loader'],
                },
            ],
        },
        plugins: [
            new MiniCssExtractPlugin({
                filename: isProduction ? '[name].[contenthash].css' : '[name].css',
            }),
            new WebpackManifestPlugin({ publicPath: '/build/' }),
        ],
        optimization: {
            minimizer: ['...', new CssMinimizerPlugin()],
        },
        devtool: isProduction ? false : 'source-map',
    };
};
