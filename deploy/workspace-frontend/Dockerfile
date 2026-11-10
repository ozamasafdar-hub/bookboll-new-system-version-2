FROM node:22-bookworm-slim AS frontend
ARG APP_DIR=web
WORKDIR /build
COPY bookboll-new-system/${APP_DIR}/package*.json ./
RUN npm ci
COPY bookboll-new-system/${APP_DIR}/ ./
ENV VITE_API_URL=/api VITE_BOOKBOLL_WORKSPACE=true
ENV VITE_PREVIEW_HOSTS=bookboll-marketplace-preview.up.railway.app
ENV VITE_WEB_URL=https://bookboll-marketplace-preview.up.railway.app VITE_BUSINESS_URL=https://bookboll-business-preview.up.railway.app VITE_ADMIN_URL=https://bookboll-admin-preview.up.railway.app
RUN npm run build

FROM nginx:stable-alpine
COPY --from=frontend /build/dist /usr/share/nginx/html
COPY deploy/workspace-frontend/default.conf.template /etc/nginx/templates/default.conf.template
ENV NGINX_ENTRYPOINT_LOCAL_RESOLVERS=1 NGINX_ENVSUBST_FILTER="BACKEND_HOST|NGINX_LOCAL_RESOLVERS"
EXPOSE 8080
