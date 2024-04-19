FROM php:7.4.33-zts-alpine3.15
COPY run /run
WORKDIR /run

RUN apk --no-cache add tzdata \  
    && ln -sf /usr/share/zoneinfo/Asia/Shanghai /etc/localtime \  
    && echo "Asia/Shanghai" > /etc/timezone

CMD ["php","./namesilo/namesilo.php"]